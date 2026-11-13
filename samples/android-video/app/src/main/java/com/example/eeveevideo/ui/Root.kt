package com.example.eeveevideo.ui

import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.rememberDrawerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.example.eeveevideo.PushOpen
import com.example.eeveevideo.data.AppContainer
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch

object Routes {
    const val VIDEOS = "videos"
    const val NOTICES = "notices"
    const val LOGIN = "login"
    const val REGISTER = "register"
    const val VIDEO = "video/{videoId}"
    const val NOTICE = "notice/{noticeId}"
    fun video(id: Long) = "video/$id"
    fun notice(id: Long) = "notice/$id"
}

@Composable
fun EeveeVideoRoot(container: AppContainer, pushOpen: MutableStateFlow<PushOpen?>) {
    EeveeTheme {
        val nav = rememberNavController()
        val scope = rememberCoroutineScope()
        val drawer = rememberDrawerState(DrawerValue.Closed)
        val session by container.session.collectAsState()
        val open by pushOpen.collectAsState()
        val backStack by nav.currentBackStackEntryAsState()
        val route = backStack?.destination?.route

        var filter by remember { mutableStateOf(VideoFilter()) }
        var categories by remember { mutableStateOf(emptyList<String>()) }
        var tags by remember { mutableStateOf(emptyList<String>()) }
        var unread by remember { mutableIntStateOf(0) }

        // メニュー用: 全動画からカテゴリとタグの一覧を作る。ログイン中は未読のお知らせ数も
        LaunchedEffect(session.loggedIn) {
            runCatching { container.api.videos().data }.onSuccess { all ->
                categories = all.mapNotNull { it.category }.distinct().sorted()
                tags = all.flatMap { it.tags }.distinct().sorted()
            }
            unread = if (session.loggedIn) runCatching { container.api.unreadNoticeCount() }.getOrDefault(0) else 0
        }

        // 通知タップ: data の type / id で画面を開く
        LaunchedEffect(open) {
            val o = open ?: return@LaunchedEffect
            val id = o.id?.toLongOrNull()
            when {
                o.type == "video" && id != null -> nav.navigate(Routes.video(id))
                o.type == "notice" && id != null -> nav.navigate(Routes.notice(id))
                o.type == "notice" -> nav.navigate(Routes.NOTICES)
            }
            pushOpen.value = null
        }

        fun closeDrawerAnd(block: () -> Unit) {
            scope.launch { drawer.close() }
            block()
        }

        ModalNavigationDrawer(
            drawerState = drawer,
            // 再生画面などではスワイプで開かないようにする
            gesturesEnabled = route == Routes.VIDEOS || route == Routes.NOTICES || drawer.isOpen,
            drawerContent = {
                AppDrawer(
                    session = session,
                    current = if (route == Routes.NOTICES) DrawerDestination.Notices else DrawerDestination.Videos,
                    filter = filter,
                    categories = categories,
                    tags = tags,
                    unreadNotices = unread,
                    onFilter = { closeDrawerAnd { filter = it; nav.goTop(Routes.VIDEOS) } },
                    onNotices = { closeDrawerAnd { nav.goTop(Routes.NOTICES) } },
                    onLogin = { closeDrawerAnd { nav.navigate(Routes.LOGIN) } },
                    onRegister = { closeDrawerAnd { nav.navigate(Routes.REGISTER) } },
                    onLogout = { closeDrawerAnd { scope.launch { container.logout() } } },
                )
            },
        ) {
            NavHost(
                navController = nav,
                startDestination = Routes.VIDEOS,
                enterTransition = { fadeIn() },
                exitTransition = { fadeOut() },
            ) {
                composable(Routes.VIDEOS) {
                    VideoListScreen(
                        container = container,
                        filter = filter,
                        onOpenMenu = { scope.launch { drawer.open() } },
                        onClearFilter = { filter = VideoFilter() },
                        onOpen = { nav.navigate(Routes.video(it)) },
                        onLogin = { nav.navigate(Routes.LOGIN) },
                        onRegister = { nav.navigate(Routes.REGISTER) },
                    )
                }
                composable(Routes.NOTICES) {
                    NoticeListScreen(
                        container = container,
                        onOpenMenu = { scope.launch { drawer.open() } },
                        onOpen = { nav.navigate(Routes.notice(it)) },
                    )
                }
                composable(Routes.NOTICE, arguments = listOf(navArgument("noticeId") { type = NavType.LongType })) { entry ->
                    NoticeDetailScreen(
                        container = container,
                        noticeId = entry.arguments?.getLong("noticeId") ?: 0L,
                        onBack = { nav.popBackStack() },
                        onUnreadChanged = { unread = it },
                    )
                }
                composable(Routes.VIDEO, arguments = listOf(navArgument("videoId") { type = NavType.LongType })) { entry ->
                    VideoDetailScreen(
                        container = container,
                        videoId = entry.arguments?.getLong("videoId") ?: 0L,
                        onBack = { nav.popBackStack() },
                        onTag = { filter = VideoFilter(tag = it); nav.goTop(Routes.VIDEOS) },
                        onCategory = { filter = VideoFilter(category = it); nav.goTop(Routes.VIDEOS) },
                        onLogin = { nav.navigate(Routes.LOGIN) },
                        onRegister = { nav.navigate(Routes.REGISTER) },
                    )
                }
                composable(Routes.LOGIN) {
                    LoginScreen(
                        container = container,
                        onDone = { nav.popBackStack() },
                        onGoRegister = { nav.navigate(Routes.REGISTER) { popUpTo(Routes.LOGIN) { inclusive = true } } },
                        onBack = { nav.popBackStack() },
                    )
                }
                composable(Routes.REGISTER) {
                    RegisterScreen(
                        container = container,
                        onDone = { nav.popBackStack() },
                        onGoLogin = { nav.navigate(Routes.LOGIN) { popUpTo(Routes.REGISTER) { inclusive = true } } },
                        onBack = { nav.popBackStack() },
                    )
                }
            }
        }
    }
}

/** メニューの移動先（動画一覧・お知らせ一覧）を、戻る履歴を積まずに開く */
private fun NavHostController.goTop(route: String) {
    navigate(route) {
        popUpTo(Routes.VIDEOS) { inclusive = route == Routes.VIDEOS }
        launchSingleTop = true
    }
}
