package com.example.eeveevideo.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.graphics.Color
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.example.eeveevideo.PushOpen
import com.example.eeveevideo.data.AppContainer
import kotlinx.coroutines.flow.MutableStateFlow

object Routes {
    const val VIDEOS = "videos"
    const val LOGIN = "login"
    const val REGISTER = "register"
    const val PLAYER = "player/{videoId}"
    fun player(id: Long) = "player/$id"
}

private val colors = lightColorScheme(primary = Color(0xFF4F46E5), secondary = Color(0xFF0EA5E9))

@Composable
fun EeveeVideoRoot(container: AppContainer, pushOpen: MutableStateFlow<PushOpen?>) {
    MaterialTheme(colorScheme = colors) {
        val nav = rememberNavController()
        val open by pushOpen.collectAsState()

        // 通知タップ: data に type=video, id={動画ID} が入っていれば、その動画の再生画面へ
        LaunchedEffect(open) {
            val o = open ?: return@LaunchedEffect
            if (o.type == "video") {
                o.id?.toLongOrNull()?.let { nav.navigate(Routes.player(it)) }
            }
            pushOpen.value = null
        }

        NavHost(navController = nav, startDestination = Routes.VIDEOS) {
            composable(Routes.VIDEOS) {
                VideoListScreen(
                    container = container,
                    onPlay = { nav.navigate(Routes.player(it)) },
                    onLogin = { nav.navigate(Routes.LOGIN) },
                    onRegister = { nav.navigate(Routes.REGISTER) },
                )
            }
            composable(Routes.LOGIN) {
                LoginScreen(
                    container = container,
                    onDone = { nav.popBackStack() },
                    onGoRegister = { nav.navigate(Routes.REGISTER) { popUpTo(Routes.VIDEOS) } },
                    onBack = { nav.popBackStack() },
                )
            }
            composable(Routes.REGISTER) {
                RegisterScreen(
                    container = container,
                    onDone = { nav.popBackStack() },
                    onGoLogin = { nav.navigate(Routes.LOGIN) { popUpTo(Routes.VIDEOS) } },
                    onBack = { nav.popBackStack() },
                )
            }
            composable(Routes.PLAYER, arguments = listOf(navArgument("videoId") { type = NavType.LongType })) { entry ->
                PlayerScreen(
                    container = container,
                    videoId = entry.arguments?.getLong("videoId") ?: 0L,
                    onBack = { nav.popBackStack() },
                    onLogin = { nav.navigate(Routes.LOGIN) { popUpTo(Routes.VIDEOS) } },
                    onRegister = { nav.navigate(Routes.REGISTER) { popUpTo(Routes.VIDEOS) } },
                )
            }
        }
    }
}
