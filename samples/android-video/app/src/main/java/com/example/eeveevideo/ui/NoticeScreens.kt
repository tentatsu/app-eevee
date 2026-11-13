package com.example.eeveevideo.ui

import android.webkit.WebView
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Campaign
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.PushPin
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import coil3.compose.AsyncImage
import com.example.eeveevideo.data.ApiException
import com.example.eeveevideo.data.AppContainer
import com.example.eeveevideo.data.Notice
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * お知らせ一覧（ピン留め → 新しい順）。ログイン中は未読に印を付ける。下に引っ張ると再読み込み。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NoticeListScreen(container: AppContainer, onOpenMenu: () -> Unit, onOpen: (Long) -> Unit) {
    val session by container.session.collectAsState()
    val scope = rememberCoroutineScope()
    var notices by remember { mutableStateOf<List<Notice>?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        error = null
        runCatching { container.api.notices().data }
            .onSuccess { notices = it }
            .onFailure { error = (it as? ApiException)?.message ?: "読み込めませんでした" }
    }

    LaunchedEffect(session.loggedIn) { load() }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Header(
            title = "お知らせ",
            subtitle = notices?.let { list ->
                val unread = list.count { !it.isRead }
                if (session.loggedIn && unread > 0) "未読のお知らせが ${unread} 件あります" else "最新の情報をお届けします"
            },
            onOpenMenu = onOpenMenu,
        )
        PullToRefreshBox(
            isRefreshing = refreshing,
            onRefresh = { scope.launch { refreshing = true; load(); refreshing = false } },
            modifier = Modifier.fillMaxSize(),
        ) {
            val list = notices
            when {
                list == null && error == null -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(5) { Box(Modifier.fillMaxWidth().height(76.dp).clip(MaterialTheme.shapes.medium).shimmer()) }
                }
                list == null -> EmptyState(Icons.Filled.CloudOff, "読み込めませんでした", error) {
                    Button(onClick = { scope.launch { load() } }) { Text("再読み込み") }
                }
                list.isEmpty() -> EmptyState(Icons.Filled.Campaign, "お知らせはまだありません", "下に引っ張ると最新の状態に更新します")
                else -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(list, key = { it.id }) { notice ->
                        NoticeRow(notice, showUnread = session.loggedIn) { onOpen(notice.id) }
                    }
                }
            }
        }
    }
}

@Composable
internal fun NoticeRow(notice: Notice, showUnread: Boolean, onClick: () -> Unit) {
    val unread = showUnread && !notice.isRead
    Card(
        onClick = onClick,
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = if (unread) 3.dp else 1.dp),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(48.dp).clip(RoundedCornerShape(12.dp)).background(Brand.placeholder(notice.title)), contentAlignment = Alignment.Center) {
                if (notice.thumbnailUrl != null) {
                    AsyncImage(model = notice.thumbnailUrl, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                } else {
                    Icon(Icons.Filled.Campaign, contentDescription = null, tint = Color.White)
                }
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (notice.isPinned) {
                        Icon(Icons.Filled.PushPin, contentDescription = "ピン留め", tint = Brand.Violet, modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(4.dp))
                    }
                    Text(formatDate(notice.publishedAt), style = MaterialTheme.typography.bodySmall, color = Color(0xFF6B7280))
                }
                Text(
                    notice.title,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = if (unread) FontWeight.Bold else FontWeight.Normal,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            if (unread) {
                Spacer(Modifier.width(8.dp))
                Box(Modifier.size(10.dp).clip(CircleShape).background(Brand.Sky))
            }
        }
    }
}

/**
 * お知らせ詳細。本文は app-eevee が返す安全な HTML（body_html）を WebView で表示する。ログイン中なら既読にする。
 */
@Composable
fun NoticeDetailScreen(container: AppContainer, noticeId: Long, onBack: () -> Unit, onUnreadChanged: (Int) -> Unit) {
    val session by container.session.collectAsState()
    var notice by remember { mutableStateOf<Notice?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(noticeId) {
        runCatching { container.api.notice(noticeId) }
            .onSuccess { n ->
                notice = n
                if (session.loggedIn && !n.isRead) {
                    runCatching { container.api.markNoticeRead(noticeId) }.onSuccess { onUnreadChanged(it.unreadCount) }
                }
            }
            .onFailure { error = (it as? ApiException)?.let { e -> if (e.code == "notice_not_found") "このお知らせは公開が終了したか、表示できません。" else e.message } ?: "読み込めませんでした" }
    }

    Column(Modifier.fillMaxSize().background(Color.White)) {
        Box(Modifier.fillMaxWidth().background(Brand.gradient).statusBarsPadding()) {
            Column(Modifier.padding(start = 4.dp, end = 16.dp, top = 4.dp, bottom = 18.dp)) {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "戻る", tint = Color.White) }
                notice?.let {
                    Text(formatDate(it.publishedAt), color = Color.White.copy(alpha = 0.85f), style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(start = 12.dp))
                    Text(it.title, color = Color.White, style = MaterialTheme.typography.headlineSmall, modifier = Modifier.padding(start = 12.dp, top = 2.dp))
                }
            }
        }
        val n = notice
        when {
            n == null && error != null -> EmptyState(Icons.Filled.CloudOff, "表示できません", error)
            n == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            else -> Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).navigationBarsPadding()) {
                n.thumbnailUrl?.let {
                    AsyncImage(model = it, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxWidth().aspectRatio(16f / 9f))
                }
                HtmlBody(n.bodyHtml ?: n.body.orEmpty())
            }
        }
    }
}

@Composable
private fun HtmlBody(html: String) {
    val page = """
        <html><head><meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
          body { font-family: sans-serif; color: #1f2430; line-height: 1.75; padding: 16px 20px; margin: 0; font-size: 15px; }
          h1, h2, h3 { line-height: 1.4; } h1 { font-size: 1.4em; } h2 { font-size: 1.2em; }
          a { color: #4F46E5; } img { max-width: 100%; border-radius: 12px; }
          blockquote { border-left: 4px solid #c7d2fe; margin: 0; padding: 4px 12px; color: #4b5563; background: #f5f7ff; }
          code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; }
        </style></head><body>$html</body></html>
    """.trimIndent()
    AndroidView(
        factory = { WebView(it).apply { settings.javaScriptEnabled = false; setBackgroundColor(android.graphics.Color.WHITE) } },
        update = { it.loadDataWithBaseURL(null, page, "text/html", "utf-8", null) },
        modifier = Modifier.fillMaxWidth(),
    )
}

private val dateFormat = DateTimeFormatter.ofPattern("yyyy年M月d日 H:mm")

/** API の UTC の日時を端末のタイムゾーンで表示する */
fun formatDate(iso: String?): String = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(dateFormat) }.getOrDefault(it)
} ?: ""
