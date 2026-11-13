package com.example.eeveevideo.data

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.example.eeveevideo.BuildConfig
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.tasks.await
import java.util.Locale
import java.util.TimeZone

/** ログイン状態 */
data class Session(val token: String? = null, val userName: String? = null) {
    val loggedIn: Boolean get() = token != null
}

/**
 * アプリ全体で使う依存関係とログイン状態。
 */
class AppContainer(private val context: Context) {

    private val store = SessionStore(context)
    private val _session = MutableStateFlow(Session())
    val session: StateFlow<Session> = _session.asStateFlow()

    val api = EeveeApi(tokenProvider = { _session.value.token })

    suspend fun restore() {
        val (token, name) = store.load()
        _session.value = Session(token, name)
    }

    suspend fun installationId(): String = store.installationId()

    suspend fun register(email: String, password: String, name: String) {
        val result = api.register(email, password, name, installationId())
        onLoggedIn(result)
    }

    suspend fun login(email: String, password: String) {
        onLoggedIn(api.login(email, password, installationId()))
    }

    suspend fun logout() {
        runCatching { api.logout(installationId()) }
        store.clearLogin()
        _session.value = Session()
    }

    /** トークンが無効（期限切れなど）だった場合 */
    suspend fun onUnauthenticated() {
        store.clearLogin()
        _session.value = Session()
    }

    private suspend fun onLoggedIn(result: AuthResult) {
        val name = result.user.name ?: result.user.email ?: "会員"
        store.saveLogin(result.token, name)
        _session.value = Session(result.token, name)
        // ログイン状態で端末を登録し直し、FCM トークンをユーザーに紐づける
        syncDevice()
    }

    /**
     * 端末を app-eevee に登録・更新する（起動時、ログイン時、FCM トークンの更新時、通知の許可が変わったとき）。
     */
    suspend fun syncDevice(newFcmToken: String? = null) {
        val token = newFcmToken ?: fcmToken()
        runCatching {
            api.registerDevice(
                installationId = installationId(),
                fcmToken = token,
                pushPermission = pushPermission(),
                appVersion = BuildConfig.VERSION_NAME,
                osVersion = Build.VERSION.RELEASE,
                locale = Locale.getDefault().toLanguageTag(),
                timezone = TimeZone.getDefault().id,
            )
        }.onFailure { e ->
            Log.w(TAG, "device registration failed", e)
            if (e is ApiException && e.code == "unauthenticated") onUnauthenticated()
        }
    }

    private suspend fun fcmToken(): String? {
        if (!BuildConfig.FCM_ENABLED || FirebaseApp.getApps(context).isEmpty()) return null
        return runCatching { FirebaseMessaging.getInstance().token.await() }
            .onFailure { Log.w(TAG, "failed to get FCM token", it) }
            .getOrNull()
    }

    private fun pushPermission(): String {
        val granted = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        } else {
            NotificationManagerCompat.from(context).areNotificationsEnabled()
        }
        // 許可されていない場合も、まだ尋ねていないだけの可能性があるため not_determined にする（許可後に syncDevice で authorized に更新する）
        return if (granted) "authorized" else "not_determined"
    }

    companion object {
        private const val TAG = "Eevee"
    }
}
