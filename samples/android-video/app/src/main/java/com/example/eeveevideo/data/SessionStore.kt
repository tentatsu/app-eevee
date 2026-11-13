package com.example.eeveevideo.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first
import java.util.UUID

private val Context.dataStore by preferencesDataStore(name = "eevee")

/**
 * 端末に保存する値。
 * - installation_id: 初回起動時に生成する UUID（アンインストールまで変えない）
 * - token: ログイン中のユーザーのトークン（本番では EncryptedSharedPreferences などで暗号化して保存することを推奨）
 */
class SessionStore(private val context: Context) {

    private val installationIdKey = stringPreferencesKey("installation_id")
    private val tokenKey = stringPreferencesKey("token")
    private val userNameKey = stringPreferencesKey("user_name")

    suspend fun installationId(): String {
        val prefs = context.dataStore.data.first()
        prefs[installationIdKey]?.let { return it }
        val id = UUID.randomUUID().toString()
        context.dataStore.edit { it[installationIdKey] = id }
        return id
    }

    suspend fun load(): Pair<String?, String?> {
        val prefs = context.dataStore.data.first()
        return prefs[tokenKey] to prefs[userNameKey]
    }

    suspend fun saveLogin(token: String, userName: String) {
        context.dataStore.edit { it[tokenKey] = token; it[userNameKey] = userName }
    }

    suspend fun clearLogin() {
        context.dataStore.edit { it.remove(tokenKey); it.remove(userNameKey) }
    }
}
