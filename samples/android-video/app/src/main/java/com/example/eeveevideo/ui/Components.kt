package com.example.eeveevideo.ui

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** 読み込み中のカードの代わりに表示する、光が流れるブロック */
@Composable
fun Modifier.shimmer(): Modifier {
    val transition = rememberInfiniteTransition(label = "shimmer")
    val x by transition.animateFloat(
        initialValue = -600f,
        targetValue = 1400f,
        animationSpec = infiniteRepeatable(tween(1300, easing = LinearEasing), RepeatMode.Restart),
        label = "shimmerX",
    )
    val brush = Brush.linearGradient(
        colors = listOf(Color(0xFFE6E8EF), Color(0xFFF4F5F9), Color(0xFFE6E8EF)),
        start = Offset(x, 0f),
        end = Offset(x + 400f, 400f),
    )
    return this.background(brush)
}

@Composable
fun VideoCardSkeleton() {
    Column(Modifier.fillMaxWidth()) {
        Box(Modifier.fillMaxWidth().height(190.dp).clip(MaterialTheme.shapes.medium).shimmer())
        Spacer(Modifier.height(10.dp))
        Box(Modifier.fillMaxWidth(0.7f).height(16.dp).clip(RoundedCornerShape(6.dp)).shimmer())
        Spacer(Modifier.height(8.dp))
        Box(Modifier.fillMaxWidth(0.4f).height(12.dp).clip(RoundedCornerShape(6.dp)).shimmer())
    }
}

/** サムネイルの右下に置く再生時間 */
@Composable
fun DurationBadge(seconds: Int?, modifier: Modifier = Modifier) {
    if (seconds == null || seconds <= 0) return
    Surface(color = Color(0xCC000000), shape = RoundedCornerShape(6.dp), modifier = modifier) {
        Text(
            formatDuration(seconds),
            color = Color.White,
            fontSize = 12.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp),
        )
    }
}

/** 会員限定の印 */
@Composable
fun MembersBadge(modifier: Modifier = Modifier) {
    Surface(color = Brand.Violet, shape = RoundedCornerShape(50), modifier = modifier) {
        Row(Modifier.padding(horizontal = 8.dp, vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Lock, contentDescription = null, tint = Color.White, modifier = Modifier.size(12.dp))
            Spacer(Modifier.width(3.dp))
            Text("会員限定", color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold)
        }
    }
}

/** カテゴリやタグの小さなラベル */
@Composable
fun Pill(text: String, color: Color = Brand.Indigo, onClick: (() -> Unit)? = null) {
    Surface(
        color = color.copy(alpha = 0.10f),
        shape = RoundedCornerShape(50),
        onClick = onClick ?: {},
        enabled = onClick != null,
    ) {
        Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
    }
}

/** 内容がないときやエラーのときの案内 */
@Composable
fun EmptyState(icon: ImageVector, title: String, message: String? = null, action: (@Composable () -> Unit)? = null) {
    Column(
        Modifier.fillMaxSize().padding(32.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(88.dp).clip(CircleShape).background(Brand.gradient), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(40.dp))
        }
        Spacer(Modifier.height(16.dp))
        Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        message?.let {
            Spacer(Modifier.height(6.dp))
            Text(it, style = MaterialTheme.typography.bodyMedium, color = Color(0xFF6B7280), textAlign = TextAlign.Center)
        }
        action?.let {
            Spacer(Modifier.height(12.dp))
            it()
        }
    }
}

fun formatDuration(seconds: Int): String {
    val h = seconds / 3600
    val m = (seconds % 3600) / 60
    val s = seconds % 60
    return if (h > 0) "%d:%02d:%02d".format(h, m, s) else "%d:%02d".format(m, s)
}

/** 「12分」「1時間5分」のような読みやすい長さ */
fun formatDurationLong(seconds: Int): String {
    val h = seconds / 3600
    val m = (seconds % 3600 + 59) / 60
    return when {
        h > 0 && m > 0 -> "${h}時間${m}分"
        h > 0 -> "${h}時間"
        seconds < 60 -> "${seconds}秒"
        else -> "${m}分"
    }
}
