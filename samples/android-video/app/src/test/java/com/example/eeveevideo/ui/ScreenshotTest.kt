package com.example.eeveevideo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.VideoLibrary
import androidx.compose.material3.Icon
import androidx.compose.material3.InputChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.example.eeveevideo.data.Notice
import com.example.eeveevideo.data.Session
import com.example.eeveevideo.data.Video
import com.github.takahirom.roborazzi.captureRoboImage
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * 主な画面のスクリーンショット（エミュレーター不要）。
 *   ./gradlew recordRoborazziDebug   → app/build/outputs/roborazzi/ に PNG を出力
 */
@RunWith(AndroidJUnit4::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [35], qualifiers = "w400dp-h860dp-xxhdpi")
class ScreenshotTest {

    private val videos = listOf(
        Video(1, "はじめての使い方ガイド", "アプリの基本的な使い方を3分で紹介します。", null, 185, "public", "チュートリアル", listOf("初心者", "使い方"), playable = true),
        Video(2, "会員限定レッスン 1：基礎のフォーム", "フォームの基本を丁寧に解説します。", null, 1260, "members", "レッスン", listOf("会員限定", "基礎"), playable = false),
        Video(3, "週末の特集：スタッフおすすめ", null, null, 4210, "public", "特集", listOf("おすすめ"), playable = true),
    )

    private val notices = listOf(
        Notice(1, "アプリをリリースしました", null, isPinned = true, publishedAt = "2026-11-01T00:00:00Z", isRead = true),
        Notice(2, "11月30日 2:00〜4:00 にメンテナンスを行います", null, publishedAt = "2026-11-12T09:00:00Z", isRead = false),
        Notice(3, "会員限定レッスンの新作を公開しました", null, publishedAt = "2026-11-13T03:30:00Z", isRead = false),
    )

    private fun shot(name: String, content: @Composable () -> Unit) =
        captureRoboImage("build/outputs/roborazzi/$name.png") { EeveeTheme { content() } }

    @Test
    fun videoList() = shot("01_video_list") {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            Header("動画", "ログインすると会員限定の動画も見られます", onOpenMenu = {})
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                items(videos) { VideoCard(it) {} }
            }
        }
    }

    @Test
    fun videoListFiltered() = shot("02_video_list_filtered") {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            Header("動画", "Taro さん、おかえりなさい", onOpenMenu = {})
            Row(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                InputChip(selected = true, onClick = {}, label = { Text("レッスン") },
                    trailingIcon = { Icon(Icons.Filled.Close, contentDescription = null) })
            }
            LazyColumn(contentPadding = PaddingValues(16.dp)) { items(videos.drop(1).take(1).map { it.copy(playable = true) }) { VideoCard(it) {} } }
        }
    }

    @Test
    fun loading() = shot("03_loading") {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            Header("動画", "ログインすると会員限定の動画も見られます", onOpenMenu = {})
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) { items(3) { VideoCardSkeleton() } }
        }
    }

    @Test
    fun loginRequired() = shot("04_login_required") {
        Box(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
                Header("動画", null, onOpenMenu = {})
                LazyColumn(contentPadding = PaddingValues(16.dp)) { items(videos.take(2)) { VideoCard(it) {} } }
            }
            LoginRequiredDialog(onRegister = {}, onLogin = {}, onDismiss = {})
        }
    }

    @Test
    fun drawer() = shot("05_drawer") {
        Box(Modifier.fillMaxSize().background(Color(0x66000000))) {
            AppDrawer(
                session = Session("t", "Taro"),
                current = DrawerDestination.Videos,
                filter = VideoFilter(category = "レッスン"),
                categories = listOf("チュートリアル", "レッスン", "特集"),
                tags = listOf("おすすめ", "初心者", "使い方", "会員限定", "基礎"),
                unreadNotices = 2,
                onFilter = {}, onNotices = {}, onLogin = {}, onRegister = {}, onLogout = {},
            )
        }
    }

    @Test
    fun videoDetail() = shot("06_video_detail") {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            Box(Modifier.fillMaxWidth().aspectRatio(16f / 9f)) { Hero(videos[1], starting = false) {} }
            VideoInfo(videos[1], playing = false, starting = false, onPlay = {}, onTag = {}, onCategory = {})
        }
    }

    @Test
    fun noticeList() = shot("07_notice_list") {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            Header("お知らせ", "未読のお知らせが 2 件あります", onOpenMenu = {})
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                items(notices.reversed()) { NoticeRow(it, showUnread = true) {} }
            }
        }
    }

    @Test
    fun empty() = shot("08_empty") {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            Header("動画", null, onOpenMenu = {})
            EmptyState(Icons.Filled.VideoLibrary, "「#おすすめ」の動画はありません", "下に引っ張ると最新の状態に更新します")
        }
    }
}
