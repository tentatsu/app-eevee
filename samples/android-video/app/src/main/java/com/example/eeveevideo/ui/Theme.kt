package com.example.eeveevideo.ui

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

object Brand {
    val Indigo = Color(0xFF4F46E5)
    val Violet = Color(0xFF7C3AED)
    val Sky = Color(0xFF0EA5E9)
    val Ink = Color(0xFF111827)
    val Surface = Color(0xFFF6F7FB)

    /** ヘッダーやヒーローに使うグラデーション */
    val gradient = Brush.linearGradient(listOf(Indigo, Violet, Sky))

    /** サムネイルの下側を暗くして文字を読みやすくする */
    val scrim = Brush.verticalGradient(listOf(Color.Transparent, Color(0xCC000000)))

    /** サムネイルがない動画の背景（カテゴリごとに色を変える） */
    private val placeholders = listOf(
        listOf(Color(0xFF4F46E5), Color(0xFF0EA5E9)),
        listOf(Color(0xFF7C3AED), Color(0xFFEC4899)),
        listOf(Color(0xFF0D9488), Color(0xFF22C55E)),
        listOf(Color(0xFFF59E0B), Color(0xFFEF4444)),
        listOf(Color(0xFF334155), Color(0xFF64748B)),
    )

    fun placeholder(seed: String?): Brush =
        Brush.linearGradient(placeholders[(seed ?: "").hashCode().mod(placeholders.size)])
}

private val colors = lightColorScheme(
    primary = Brand.Indigo,
    secondary = Brand.Violet,
    tertiary = Brand.Sky,
    background = Brand.Surface,
    surface = Color.White,
    onSurface = Brand.Ink,
)

private val typography = Typography(
    headlineSmall = TextStyle(fontWeight = FontWeight.Bold, fontSize = 22.sp, lineHeight = 30.sp),
    titleMedium = TextStyle(fontWeight = FontWeight.Bold, fontSize = 16.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 22.sp),
)

private val shapes = Shapes(
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
)

@Composable
fun EeveeTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = colors, typography = typography, shapes = shapes, content = content)
}
