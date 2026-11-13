package com.example.eeveevideo.ui

import androidx.annotation.OptIn
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.media3.common.MediaItem
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import com.example.eeveevideo.data.ApiException
import com.example.eeveevideo.data.AppContainer
import com.example.eeveevideo.data.PlayResult
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * 再生画面。
 * 1. POST /videos/{id}/play で視聴セッションと署名付き HLS の URL を受け取る
 * 2. 再生中は heartbeat_interval（30）秒ごとに heartbeat を送る。action=stop なら止める
 * 3. 画面を閉じたら stop を送る（同時視聴数の枠を空けるため）
 */
@kotlin.OptIn(ExperimentalMaterial3Api::class)
@OptIn(UnstableApi::class)
@Composable
fun PlayerScreen(container: AppContainer, videoId: Long, onBack: () -> Unit, onLogin: () -> Unit, onRegister: () -> Unit) {
    val context = LocalContext.current
    var play by remember { mutableStateOf<PlayResult?>(null) }
    var error by remember { mutableStateOf<Pair<String, String>?>(null) }
    var loginRequired by remember { mutableStateOf(false) }
    val player = remember { ExoPlayer.Builder(context).build() }

    // 再生の開始
    LaunchedEffect(videoId) {
        try {
            val result = container.api.play(videoId, container.installationId())
            play = result
            player.setMediaItem(MediaItem.fromUri(result.hlsUrl))
            player.prepare()
            player.playWhenReady = true
        } catch (e: ApiException) {
            when (e.code) {
                "login_required" -> loginRequired = true
                "unauthenticated" -> { container.onUnauthenticated(); loginRequired = true }
                "concurrent_limit_exceeded" -> error = "同時に視聴できる数の上限です" to "他の端末での再生を終了してから、もう一度お試しください。"
                "video_not_available" -> error = "再生できません" to "この動画は公開されていないか、準備中です。"
                else -> error = "再生できません" to (e.message ?: "")
            }
        }
    }

    // ハートビート
    LaunchedEffect(play) {
        val session = play ?: return@LaunchedEffect
        while (true) {
            delay(session.heartbeatInterval * 1000L)
            val result = runCatching { container.api.heartbeat(session.sessionId, container.installationId()) }.getOrNull() ?: continue
            if (result.action == "stop") {
                player.stop()
                error = when (result.reason) {
                    "kicked" -> "再生を停止しました" to "他の端末で再生が始まったため、この端末での再生を停止しました。"
                    "expired" -> "再生を停止しました" to "通信が途切れたため再生を停止しました。もう一度再生してください。"
                    else -> "再生を停止しました" to "再生は終了しました。"
                }
                break
            }
        }
    }

    // 画面を閉じたら停止を送る（画面が消えても送れるよう、画面とは別のスコープで送る）
    val currentPlay by rememberUpdatedState(play)
    DisposableEffect(Unit) {
        onDispose {
            player.release()
            currentPlay?.let { session ->
                CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
                    runCatching { container.api.stop(session.sessionId, container.installationId()) }
                }
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("再生") },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "戻る") } },
            )
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            Box(Modifier.fillMaxWidth().aspectRatio(16f / 9f).background(Color.Black), contentAlignment = Alignment.Center) {
                if (play != null) {
                    AndroidView(factory = { PlayerView(it).apply { this.player = player } }, modifier = Modifier.fillMaxSize())
                } else if (error == null && !loginRequired) {
                    CircularProgressIndicator(color = Color.White)
                }
            }
            play?.let {
                Text("視聴セッション: ${it.sessionId.take(8)}…（${it.heartbeatInterval}秒ごとにハートビート）",
                    style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(12.dp))
            }
        }
    }

    if (loginRequired) {
        LoginRequiredDialog(
            onRegister = { loginRequired = false; onRegister() },
            onLogin = { loginRequired = false; onLogin() },
            onDismiss = { loginRequired = false; onBack() },
        )
    }
    error?.let { (title, message) ->
        MessageDialog(title, message) { error = null; onBack() }
    }
}
