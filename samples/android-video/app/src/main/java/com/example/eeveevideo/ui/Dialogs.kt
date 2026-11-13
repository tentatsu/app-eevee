package com.example.eeveevideo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp

/**
 * 未ログインの人が会員限定の動画を見ようとしたときのポップアップ。
 */
@Composable
fun LoginRequiredDialog(onRegister: () -> Unit, onLogin: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = Color.White,
        icon = {
            Box(Modifier.size(56.dp).clip(CircleShape).background(Brand.gradient), contentAlignment = Alignment.Center) {
                Icon(Icons.Filled.Lock, contentDescription = null, tint = Color.White)
            }
        },
        title = { Text("会員限定の動画です", textAlign = TextAlign.Center) },
        text = {
            Text(
                "この動画を見るには、会員登録またはログインが必要です。",
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
        },
        confirmButton = {
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = onRegister, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text("会員登録") }
                OutlinedButton(onClick = onLogin, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text("ログイン") }
                Spacer(Modifier.height(2.dp))
                TextButton(onClick = onDismiss, modifier = Modifier.fillMaxWidth()) { Text("あとで", color = Color(0xFF6B7280)) }
            }
        },
    )
}

@Composable
fun MessageDialog(title: String, message: String, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = Color.White,
        title = { Text(title) },
        text = { Text(message) },
        confirmButton = { TextButton(onClick = onDismiss) { Text("OK") } },
    )
}
