package com.example.eeveevideo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Login
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.Campaign
import androidx.compose.material.icons.filled.Category
import androidx.compose.material.icons.filled.Movie
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material3.Badge
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.NavigationDrawerItem
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.example.eeveevideo.data.Session

/** 動画の絞り込み条件（null ならすべて） */
data class VideoFilter(val category: String? = null, val tag: String? = null) {
    val isAll: Boolean get() = category == null && tag == null
    val label: String get() = category ?: tag?.let { "#$it" } ?: "すべての動画"
}

enum class DrawerDestination { Videos, Notices }

/**
 * 左からスライドして開くメニュー。動画の絞り込み（カテゴリ・タグ）、お知らせ、アカウント。
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun AppDrawer(
    session: Session,
    current: DrawerDestination,
    filter: VideoFilter,
    categories: List<String>,
    tags: List<String>,
    unreadNotices: Int,
    onFilter: (VideoFilter) -> Unit,
    onNotices: () -> Unit,
    onLogin: () -> Unit,
    onRegister: () -> Unit,
    onLogout: () -> Unit,
) {
    ModalDrawerSheet(drawerContainerColor = Color.White) {
        Column(Modifier.verticalScroll(rememberScrollState())) {
            // ヘッダー
            Box(Modifier.fillMaxWidth().background(Brand.gradient).statusBarsPadding().padding(20.dp)) {
                Column {
                    Box(Modifier.size(52.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.PlayCircle, contentDescription = null, tint = Color.White, modifier = Modifier.size(32.dp))
                    }
                    Spacer(Modifier.height(12.dp))
                    Text("Eevee Video", color = Color.White, style = MaterialTheme.typography.headlineSmall)
                    Text(
                        if (session.loggedIn) "${session.userName} さん" else "ゲストで閲覧中",
                        color = Color.White.copy(alpha = 0.85f),
                    )
                }
            }

            Spacer(Modifier.height(8.dp))
            NavigationDrawerItem(
                label = { Text("すべての動画") },
                icon = { Icon(Icons.Filled.Movie, contentDescription = null) },
                selected = current == DrawerDestination.Videos && filter.isAll,
                onClick = { onFilter(VideoFilter()) },
                modifier = Modifier.padding(horizontal = 12.dp),
            )
            NavigationDrawerItem(
                label = { Text("お知らせ") },
                icon = { Icon(Icons.Filled.Campaign, contentDescription = null) },
                badge = { if (unreadNotices > 0) Badge { Text(if (unreadNotices > 99) "99+" else unreadNotices.toString()) } },
                selected = current == DrawerDestination.Notices,
                onClick = onNotices,
                modifier = Modifier.padding(horizontal = 12.dp),
            )

            if (categories.isNotEmpty()) {
                SectionTitle("カテゴリ")
                categories.forEach { category ->
                    NavigationDrawerItem(
                        label = { Text(category) },
                        icon = { Icon(Icons.Filled.Category, contentDescription = null) },
                        selected = current == DrawerDestination.Videos && filter.category == category,
                        onClick = { onFilter(VideoFilter(category = category)) },
                        modifier = Modifier.padding(horizontal = 12.dp),
                    )
                }
            }

            if (tags.isNotEmpty()) {
                SectionTitle("タグ")
                FlowRow(
                    Modifier.padding(horizontal = 20.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    tags.forEach { tag ->
                        FilterChip(
                            selected = current == DrawerDestination.Videos && filter.tag == tag,
                            onClick = { onFilter(VideoFilter(tag = tag)) },
                            label = { Text("#$tag") },
                        )
                    }
                }
            }

            Spacer(Modifier.height(12.dp))
            HorizontalDivider(Modifier.padding(horizontal = 20.dp))
            SectionTitle("アカウント")
            if (session.loggedIn) {
                NavigationDrawerItem(
                    label = { Text("ログアウト") },
                    icon = { Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null) },
                    selected = false,
                    onClick = onLogout,
                    modifier = Modifier.padding(horizontal = 12.dp),
                )
            } else {
                NavigationDrawerItem(
                    label = { Text("ログイン") },
                    icon = { Icon(Icons.AutoMirrored.Filled.Login, contentDescription = null) },
                    selected = false,
                    onClick = onLogin,
                    modifier = Modifier.padding(horizontal = 12.dp),
                )
                NavigationDrawerItem(
                    label = { Text("会員登録") },
                    icon = { Icon(Icons.Filled.PersonAdd, contentDescription = null) },
                    selected = false,
                    onClick = onRegister,
                    modifier = Modifier.padding(horizontal = 12.dp),
                )
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Row(Modifier.padding(start = 28.dp, top = 16.dp, bottom = 6.dp)) {
        Text(text, style = MaterialTheme.typography.labelMedium, color = Color(0xFF6B7280), fontWeight = FontWeight.Bold)
    }
    Spacer(Modifier.width(0.dp))
}
