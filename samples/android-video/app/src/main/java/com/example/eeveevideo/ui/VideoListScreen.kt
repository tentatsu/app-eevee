package com.example.eeveevideo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import com.example.eeveevideo.data.ApiException
import com.example.eeveevideo.data.AppContainer
import com.example.eeveevideo.data.Video
import kotlinx.coroutines.launch

/**
 * 動画一覧。ログインの有無に関わらず全動画を表示する（会員限定には鍵マーク）。
 * 未ログインで会員限定の動画をタップすると、会員登録・ログインを促すポップアップを出す。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun VideoListScreen(container: AppContainer, onPlay: (Long) -> Unit, onLogin: () -> Unit, onRegister: () -> Unit) {
    val session by container.session.collectAsState()
    val scope = rememberCoroutineScope()
    var videos by remember { mutableStateOf<List<Video>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var loginRequired by remember { mutableStateOf(false) }

    suspend fun load() {
        loading = true
        error = null
        try {
            videos = container.api.videos().data
        } catch (e: ApiException) {
            if (e.code == "unauthenticated") container.onUnauthenticated()
            error = e.message
        } finally {
            loading = false
        }
    }

    // ログイン状態が変わったら読み直す（playable が変わるため）
    LaunchedEffect(session.loggedIn) { load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("動画") },
                actions = {
                    if (session.loggedIn) {
                        Text(session.userName ?: "", style = MaterialTheme.typography.bodySmall)
                        TextButton(onClick = { scope.launch { container.logout() } }) { Text("ログアウト") }
                    } else {
                        TextButton(onClick = onLogin) { Text("ログイン") }
                    }
                },
            )
        },
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = loading && videos.isNotEmpty(),
            onRefresh = { scope.launch { load() } },
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            when {
                loading && videos.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                error != null && videos.isEmpty() -> Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(error ?: "")
                    TextButton(onClick = { scope.launch { load() } }) { Text("再読み込み") }
                }
                videos.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("動画がありません") }
                else -> LazyColumn(contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(videos, key = { it.id }) { video ->
                        VideoCard(video) {
                            if (video.playable) onPlay(video.id) else loginRequired = true
                        }
                    }
                }
            }
        }
    }

    if (loginRequired) {
        LoginRequiredDialog(
            onRegister = { loginRequired = false; onRegister() },
            onLogin = { loginRequired = false; onLogin() },
            onDismiss = { loginRequired = false },
        )
    }
}

@Composable
private fun VideoCard(video: Video, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick)) {
        Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.width(128.dp).height(72.dp).clip(RoundedCornerShape(8.dp)).background(Color(0xFF1F2430)),
                contentAlignment = Alignment.Center,
            ) {
                if (video.thumbnailUrl != null) {
                    AsyncImage(model = video.thumbnailUrl, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                }
                Icon(
                    if (video.playable) Icons.Filled.PlayArrow else Icons.Filled.Lock,
                    contentDescription = null,
                    tint = Color.White,
                    modifier = Modifier.size(28.dp),
                )
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(video.title, fontWeight = FontWeight.Bold, maxLines = 2)
                Spacer(Modifier.height(4.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    video.durationSeconds?.let { Text(formatDuration(it), style = MaterialTheme.typography.bodySmall) }
                    if (video.membersOnly) {
                        Spacer(Modifier.width(8.dp))
                        AssistChip(onClick = onClick, label = { Text("会員限定") })
                    }
                }
            }
        }
    }
}

fun formatDuration(seconds: Int): String {
    val h = seconds / 3600
    val m = (seconds % 3600) / 60
    val s = seconds % 60
    return if (h > 0) "%d:%02d:%02d".format(h, m, s) else "%d:%02d".format(m, s)
}
