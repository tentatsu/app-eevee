package com.example.eeveevideo.data

/**
 * app-eevee が返したエラー。code は機械が読めるコード（例: login_required, concurrent_limit_exceeded）。
 */
class ApiException(val status: Int, val code: String, message: String) : Exception(message)
