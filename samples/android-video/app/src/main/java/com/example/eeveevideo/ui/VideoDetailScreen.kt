package com.example.eeveevideo.ui

import androidx.annotation.OptIn
import androidx.compose.animation.Crossfade
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.media3.common.MediaItem
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import coil3.compose.AsyncImage
import com.example.eeveevideo.data.ApiException
import com.example.eeveevideo.data.AppContainer
import com.example.eeveevideo.data.PlayResult
import com.example.eeveevideo.data.Video
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * 動画詳細。上部で再生し、下に説明・カテゴリ・タグ・再生時間を表示する。
 *
 * 再生の流れ（app-eevee の API）:
 * 1. POST /videos/{id}/play で視聴セッションと署名付き HLS の URL を受け取る
 * 2. 再生中は heartbeat_interval（30）秒ごとに heartbeat。action=stop なら止める
 * 3. 画面を閉じたら stop を送る（同時視聴数の枠を空けるため）
 */
@OptIn(UnstableApi::class, ExperimentalLayoutApi::class)
@Composable
fun VideoDetailScreen(
    container: AppContainer,
    videoId: Long,
    onBack: () -> Unit,
    onTag: (String) -> Unit,
    onCategory: (String) -> Unit,
    onLogin: () -> Unit,
    onRegister: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val session by container.session.collectAsState()
    var video by remember { mutableStateOf<Video?>(null) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var play by remember { mutableStateOf<PlayResult?>(null) }
    var starting by remember { mutableStateOf(false) }
    var dialog by remember { mutableStateOf<Pair<String, String>?>(null) }
    var loginRequired by remember { mutableStateOf(false) }
    val player = remember { ExoPlayer.Builder(context).build() }

    // ログイン状態で playable が変わるので、ログイン後に戻ってきたら読み直す
    LaunchedEffect(videoId, session.loggedIn) {
        loadError = null
        runCatching { container.api.video(videoId) }
            .onSuccess { video = it }
            .onFailure { loadError = (it as? ApiException)?.message ?: "読み込めませんでした" }
    }

    fun startPlayback() {
        val v = video ?: return
        if (!v.playable) {
            loginRequired = true
            return
        }
        starting = true
        scope.launch {
            try {
                val result = container.api.play(v.id, container.installationId())
                player.setMediaItem(MediaItem.fromUri(result.hlsUrl))
                player.prepare()
                player.playWhenReady = true
                play = result
            } catch (e: ApiException) {
                when (e.code) {
                    "login_required" -> loginRequired = true
                    "unauthenticated" -> { container.onUnauthenticated(); loginRequired = true }
                    "concurrent_limit_exceeded" -> dialog = "同時に視聴できる数の上限です" to "他の端末での再生を終了してから、もう一度お試しください。"
                    "video_not_available" -> dialog = "再生できません" to "この動画は公開されていないか、準備中です。"
                    else -> dialog = "再生できません" to (e.message ?: "")
                }
            } finally {
                starting = false
            }
        }
    }

    // ハートビート
    LaunchedEffect(play) {
        val session0 = play ?: return@LaunchedEffect
        while (true) {
            delay(session0.heartbeatInterval * 1000L)
            val result = runCatching { container.api.heartbeat(session0.sessionId, container.installationId()) }.getOrNull() ?: continue
            if (result.action == "stop") {
                player.stop()
                play = null
                dialog = "再生を停止しました" to when (result.reason) {
                    "kicked" -> "他の端末で再生が始まったため、この端末での再生を停止しました。"
                    "expired" -> "通信が途切れたため再生を停止しました。もう一度再生してください。"
                    else -> "再生は終了しました。"
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
            currentPlay?.let { s ->
                CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
                    runCatching { container.api.stop(s.sessionId, container.installationId()) }
                }
            }
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        // ---- 上部: 再生エリア ----
        Box(Modifier.fillMaxWidth().background(Color.Black).statusBarsPadding()) {
            Box(Modifier.fillMaxWidth().aspectRatio(16f / 9f)) {
                Crossfade(targetState = play != null, label = "player") { playing ->
                    if (playing) {
                        AndroidView(factory = { PlayerView(it).apply { this.player = player } }, modifier = Modifier.fillMaxSize())
                    } else {
                        Hero(video, starting) { startPlayback() }
                    }
                }
                IconButton(
                    onClick = onBack,
                    colors = IconButtonDefaults.iconButtonColors(containerColor = Color(0x66000000)),
                    modifier = Modifier.align(Alignment.TopStart).padding(8.dp),
                ) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "戻る", tint = Color.White) }
            }
        }

        // ---- 下部: 動画の情報 ----
        val v = video
        when {
            v == null && loadError != null -> EmptyState(Icons.Filled.CloudOff, "読み込めませんでした", loadError)
            v == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            else -> VideoInfo(v, playing = play != null, starting = starting, onPlay = { startPlayback() }, onTag = onTag, onCategory = onCategory)
        }
    }

    if (loginRequired) {
        LoginRequiredDialog(
            onRegister = { loginRequired = false; onRegister() },
            onLogin = { loginRequired = false; onLogin() },
            onDismiss = { loginRequired = false },
        )
    }
    dialog?.let { (title, message) -> MessageDialog(title, message) { dialog = null } }
}

/** 動画の情報（タイトル・再生時間・カテゴリ・再生ボタン・説明・タグ） */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun VideoInfo(v: Video, playing: Boolean, starting: Boolean, onPlay: () -> Unit, onTag: (String) -> Unit, onCategory: (String) -> Unit) {
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp).navigationBarsPadding(),
    ) {
        Text(v.title, style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            v.durationSeconds?.takeIf { it > 0 }?.let {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.Schedule, contentDescription = null, tint = Color(0xFF6B7280), modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(4.dp))
                    Text(formatDurationLong(it), style = MaterialTheme.typography.bodyMedium, color = Color(0xFF6B7280))
                }
            }
            v.category?.let { Pill(it, Brand.Indigo) { onCategory(it) } }
            if (v.membersOnly) MembersBadge()
        }

        Spacer(Modifier.height(18.dp))
        if (!playing) {
            Button(
                onClick = onPlay,
                enabled = !starting,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                colors = ButtonDefaults.buttonColors(containerColor = if (v.playable) Brand.Indigo else Brand.Violet),
            ) {
                Icon(if (v.playable) Icons.Filled.PlayArrow else Icons.Filled.Lock, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text(if (v.playable) "再生する" else "会員登録・ログインして見る")
            }
            Spacer(Modifier.height(18.dp))
        }

        if (!v.description.isNullOrBlank()) {
            Text("この動画について", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(6.dp))
            Text(v.description, style = MaterialTheme.typography.bodyMedium, color = Color(0xFF374151))
            Spacer(Modifier.height(18.dp))
        }

        if (v.tags.isNotEmpty()) {
            HorizontalDivider()
            Spacer(Modifier.height(14.dp))
            Text("タグ", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                v.tags.forEach { tag -> Pill("#$tag", Brand.Violet) { onTag(tag) } }
            }
        }
    }
}

/** 再生前のサムネイルと大きな再生ボタン */
@Composable
internal fun Hero(video: Video?, starting: Boolean, onPlay: () -> Unit) {
    Box(
        Modifier.fillMaxSize().background(Brand.placeholder(video?.category ?: video?.title)).clickable(enabled = video != null && !starting, onClick = onPlay),
        contentAlignment = Alignment.Center,
    ) {
        video?.thumbnailUrl?.let { AsyncImage(model = it, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize()) }
        Box(Modifier.fillMaxSize().background(Brand.scrim))
        if (starting) {
            CircularProgressIndicator(color = Color.White)
        } else if (video != null) {
            Box(Modifier.size(72.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.92f)), contentAlignment = Alignment.Center) {
                Icon(
                    if (video.playable) Icons.Filled.PlayArrow else Icons.Filled.Lock,
                    contentDescription = if (video.playable) "再生" else "会員限定",
                    tint = Brand.Indigo,
                    modifier = Modifier.size(40.dp),
                )
            }
        }
        DurationBadge(video?.durationSeconds, Modifier.align(Alignment.BottomEnd).padding(12.dp))
    }
}
