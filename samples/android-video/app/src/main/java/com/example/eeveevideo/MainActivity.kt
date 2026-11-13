package com.example.eeveevideo

import android.Manifest
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.lifecycle.lifecycleScope
import com.example.eeveevideo.ui.EeveeVideoRoot
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch

/**
 * 通知をタップして開いたときの data（app-eevee は eevee_campaign_id と、管理画面で設定した data を送る）。
 */
data class PushOpen(val campaignId: Long?, val type: String?, val id: String?)

class MainActivity : ComponentActivity() {

    private val container by lazy { (application as EeveeVideoApp).container }

    /** 通知タップで開くべき画面（UI 側で処理したら null に戻す） */
    val pushOpen = MutableStateFlow<PushOpen?>(null)

    private val notificationPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) {
        // 許可・不許可が決まったら、通知の許可状態を app-eevee に送り直す
        lifecycleScope.launch { container.syncDevice() }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        lifecycleScope.launch {
            container.restore()
            container.syncDevice()   // 起動のたびに端末を登録・更新する
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
        handlePushIntent(intent)

        setContent { EeveeVideoRoot(container, pushOpen) }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handlePushIntent(intent)
    }

    private fun handlePushIntent(intent: Intent?) {
        val extras = intent?.extras ?: return
        val campaignId = extras.getString("eevee_campaign_id")?.toLongOrNull() ?: return
        pushOpen.value = PushOpen(campaignId, extras.getString("type"), extras.getString("id"))

        // 開封を記録する（何度呼んでも 1 回として数えられる）
        lifecycleScope.launch {
            runCatching { container.api.pushOpened(campaignId, container.installationId()) }
        }
        intent.removeExtra("eevee_campaign_id")
    }
}
