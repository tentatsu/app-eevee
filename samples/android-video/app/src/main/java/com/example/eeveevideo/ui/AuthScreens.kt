package com.example.eeveevideo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.example.eeveevideo.data.ApiException
import com.example.eeveevideo.data.AppContainer
import kotlinx.coroutines.launch

@Composable
private fun AuthScaffold(title: String, onBack: () -> Unit, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Color.White).imePadding()) {
        Box(Modifier.fillMaxWidth().background(Brand.gradient).statusBarsPadding()) {
            Column(Modifier.padding(start = 4.dp, end = 16.dp, top = 4.dp, bottom = 22.dp)) {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "戻る", tint = androidx.compose.ui.graphics.Color.White) }
                Text(title, style = MaterialTheme.typography.headlineSmall, color = androidx.compose.ui.graphics.Color.White, modifier = Modifier.padding(start = 12.dp))
            }
        }
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) { content() }
    }
}

@Composable
fun LoginScreen(container: AppContainer, onDone: () -> Unit, onGoRegister: () -> Unit, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    AuthScaffold("ログイン", onBack) {
        OutlinedTextField(email, { email = it }, label = { Text("メールアドレス") }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
        OutlinedTextField(password, { password = it }, label = { Text("パスワード") }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), modifier = Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            enabled = !busy && email.isNotBlank() && password.isNotBlank(),
            onClick = {
                busy = true; error = null
                scope.launch {
                    try {
                        container.login(email.trim(), password)
                        onDone()
                    } catch (e: ApiException) {
                        error = when (e.code) {
                            "invalid_credentials" -> "メールアドレスまたはパスワードが正しくありません。"
                            "rate_limited" -> "しばらく時間をおいてからお試しください。"
                            else -> e.message
                        }
                    } finally {
                        busy = false
                    }
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) { Text("ログイン") }
        TextButton(onClick = onGoRegister) { Text("アカウントをお持ちでない方は会員登録") }
    }
}

@Composable
fun RegisterScreen(container: AppContainer, onDone: () -> Unit, onGoLogin: () -> Unit, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    AuthScaffold("会員登録", onBack) {
        OutlinedTextField(name, { name = it }, label = { Text("お名前") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(email, { email = it }, label = { Text("メールアドレス") }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
        OutlinedTextField(password, { password = it }, label = { Text("パスワード（8文字以上）") }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), modifier = Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            enabled = !busy && name.isNotBlank() && email.isNotBlank() && password.length >= 8,
            onClick = {
                busy = true; error = null
                scope.launch {
                    try {
                        container.register(email.trim(), password, name.trim())
                        onDone()
                    } catch (e: ApiException) {
                        error = when (e.code) {
                            "validation_failed" -> "入力内容を確認してください（このメールアドレスは登録済みの可能性があります）。"
                            "auth_mode_mismatch" -> "このアプリはアプリ内での会員登録に対応していません。"
                            else -> e.message
                        }
                    } finally {
                        busy = false
                    }
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) { Text("登録する") }
        TextButton(onClick = onGoLogin) { Text("すでにアカウントをお持ちの方はログイン") }
    }
}
