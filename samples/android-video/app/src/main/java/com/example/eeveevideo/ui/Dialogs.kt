package com.example.eeveevideo.ui

import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable

/**
 * 未ログインの人が会員限定の動画を見ようとしたときのポップアップ。
 */
@Composable
fun LoginRequiredDialog(onRegister: () -> Unit, onLogin: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("会員限定の動画です") },
        text = { Text("この動画を見るには、会員登録またはログインが必要です。") },
        confirmButton = { TextButton(onClick = onRegister) { Text("会員登録") } },
        dismissButton = {
            androidx.compose.foundation.layout.Row {
                TextButton(onClick = onDismiss) { Text("キャンセル") }
                TextButton(onClick = onLogin) { Text("ログイン") }
            }
        },
    )
}

@Composable
fun MessageDialog(title: String, message: String, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { Text(message) },
        confirmButton = { TextButton(onClick = onDismiss) { Text("OK") } },
    )
}
