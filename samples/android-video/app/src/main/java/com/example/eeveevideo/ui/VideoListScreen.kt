package com.example.eeveevideo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.VideoLibrary
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.InputChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import com.example.eeveevideo.data.ApiException
import com.example.eeveevideo.data.AppContainer
import com.example.eeveevideo.data.Video
import kotlinx.coroutines.launch

/**
 * 動画一覧。ログインの有無に関わらず全動画を表示する（会員限定には鍵と覆い）。
 * 未ログインで会員限定の動画をタップすると、会員登録・ログインを促すポップアップを出す。
 * 下に引っ張ると再読み込み。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun VideoListScreen(
    container: AppContainer,
    filter: VideoFilter,
    onOpenMenu: () -> Unit,
    onClearFilter: () -> Unit,
    onOpen: (Long) -> Unit,
    onLogin: () -> Unit,
    onRegister: () -> Unit,
) {
    val session by container.session.collectAsState()
    val scope = rememberCoroutineScope()
    var videos by remember { mutableStateOf<List<Video>?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var loginRequired by remember { mutableStateOf(false) }

    suspend fun load() {
        error = null
        try {
            videos = container.api.videos(filter.category, filter.tag).data
        } catch (e: ApiException) {
            if (e.code == "unauthenticated") container.onUnauthenticated()
            error = e.message
        }
    }

    // ログイン状態（playable が変わる）や絞り込みが変わったら読み直す
    LaunchedEffect(session.loggedIn, filter) {
        videos = null
        load()
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Header(
            title = "動画",
            subtitle = if (session.loggedIn) "${session.userName} さん、おかえりなさい" else "ログインすると会員限定の動画も見られます",
            onOpenMenu = onOpenMenu,
            action = {
                if (!session.loggedIn) {
                    TextButton(onClick = onLogin) { Text("ログイン", color = Color.White) }
                }
            },
        )

        if (!filter.isAll) {
            Row(Modifier.padding(horizontal = 16.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                InputChip(
                    selected = true,
                    onClick = onClearFilter,
                    label = { Text(filter.label) },
                    trailingIcon = { Icon(Icons.Filled.Close, contentDescription = "絞り込みを解除", modifier = Modifier.size(16.dp)) },
                )
                Spacer(Modifier.width(8.dp))
                videos?.let { Text("${it.size}件", style = MaterialTheme.typography.bodySmall, color = Color(0xFF6B7280)) }
            }
        }

        PullToRefreshBox(
            isRefreshing = refreshing,
            onRefresh = { scope.launch { refreshing = true; load(); refreshing = false } },
            modifier = Modifier.fillMaxSize(),
        ) {
            val list = videos
            when {
                list == null && error == null -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                    items(3) { VideoCardSkeleton() }
                }
                list == null -> EmptyState(Icons.Filled.CloudOff, "読み込めませんでした", error) {
                    Button(onClick = { scope.launch { load() } }) { Text("再読み込み") }
                }
                list.isEmpty() -> EmptyState(
                    Icons.Filled.VideoLibrary,
                    if (filter.isAll) "まだ動画がありません" else "「${filter.label}」の動画はありません",
                    "下に引っ張ると最新の状態に更新します",
                )
                else -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                    items(list, key = { it.id }) { video ->
                        // 並び替え・絞り込みのときにふわっと入れ替わる
                        VideoCard(video, Modifier.animateItem()) {
                            if (video.playable) onOpen(video.id) else loginRequired = true
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

/** グラデーションのヘッダー（左にハンバーガーメニュー） */
@Composable
fun Header(title: String, subtitle: String?, onOpenMenu: () -> Unit, action: @Composable () -> Unit = {}) {
    Box(Modifier.fillMaxWidth().background(Brand.gradient).statusBarsPadding()) {
        Column(Modifier.padding(start = 4.dp, end = 8.dp, top = 4.dp, bottom = 18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = onOpenMenu) { Icon(Icons.Filled.Menu, contentDescription = "メニュー", tint = Color.White) }
                Text(title, style = MaterialTheme.typography.headlineSmall, color = Color.White, modifier = Modifier.weight(1f))
                action()
            }
            subtitle?.let {
                Text(it, color = Color.White.copy(alpha = 0.85f), style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(start = 16.dp))
            }
        }
    }
}

@Composable
internal fun VideoCard(video: Video, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(modifier.fillMaxWidth().clickable(onClick = onClick)) {
        Box(
            Modifier.fillMaxWidth().aspectRatio(16f / 9f)
                .shadow(6.dp, MaterialTheme.shapes.medium)
                .clip(MaterialTheme.shapes.medium)
                .background(Brand.placeholder(video.category ?: video.title)),
        ) {
            if (video.thumbnailUrl != null) {
                AsyncImage(model = video.thumbnailUrl, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            }
            Box(Modifier.fillMaxSize().background(Brand.scrim))
            if (!video.playable) {
                // 未ログインで見られない動画は暗くして鍵を出す
                Box(Modifier.fillMaxSize().background(Color(0x66000000)))
            }
            Box(
                Modifier.align(Alignment.Center).size(56.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.92f)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    if (video.playable) Icons.Filled.PlayArrow else Icons.Filled.Lock,
                    contentDescription = null,
                    tint = Brand.Indigo,
                    modifier = Modifier.size(30.dp),
                )
            }
            if (video.membersOnly) MembersBadge(Modifier.align(Alignment.TopStart).padding(10.dp))
            DurationBadge(video.durationSeconds, Modifier.align(Alignment.BottomEnd).padding(10.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(video.title, style = MaterialTheme.typography.titleMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
        val meta = listOfNotNull(video.category, video.tags.takeIf { it.isNotEmpty() }?.joinToString(" ") { "#$it" })
        if (meta.isNotEmpty()) {
            Spacer(Modifier.height(2.dp))
            Text(meta.joinToString("  ·  "), style = MaterialTheme.typography.bodySmall, color = Color(0xFF6B7280), maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}
