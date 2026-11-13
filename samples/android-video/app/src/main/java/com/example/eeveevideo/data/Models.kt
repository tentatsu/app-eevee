package com.example.eeveevideo.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** app-eevee の成功レスポンス: {"data": ..., "meta": {...}} */
@Serializable
data class Envelope<T>(val data: T, val meta: PageMeta? = null)

@Serializable
data class PageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    val total: Int = 0,
)

/** 失敗レスポンス: {"error": {"code", "message", "details"}} */
@Serializable
data class ErrorEnvelope(val error: ErrorBody)

@Serializable
data class ErrorBody(val code: String, val message: String = "")

@Serializable
data class AppUser(
    val id: Long,
    val email: String? = null,
    val name: String? = null,
)

@Serializable
data class AuthResult(
    val token: String,
    @SerialName("expires_at") val expiresAt: String? = null,
    val user: AppUser,
)

@Serializable
data class Video(
    val id: Long,
    val title: String,
    val description: String? = null,
    @SerialName("thumbnail_url") val thumbnailUrl: String? = null,
    @SerialName("duration_seconds") val durationSeconds: Int? = null,
    /** public = 誰でも視聴可 / members = ログインが必要 */
    val visibility: String,
    val category: String? = null,
    val tags: List<String> = emptyList(),
    /** 今の状態（ログインの有無）で再生できるか */
    val playable: Boolean,
) {
    val membersOnly: Boolean get() = visibility == "members"
}

@Serializable
data class PlayResult(
    @SerialName("session_id") val sessionId: String,
    @SerialName("hls_url") val hlsUrl: String,
    @SerialName("expires_at") val expiresAt: String,
    @SerialName("heartbeat_interval") val heartbeatInterval: Int = 30,
)

@Serializable
data class HeartbeatResult(
    /** continue = 続行 / stop = 再生を止める */
    val action: String,
    /** kicked = 他の端末で再生が始まった / expired = ハートビートが途絶えた / stopped = 終了済み */
    val reason: String? = null,
)

@Serializable
data class Notice(
    val id: Long,
    val title: String,
    @SerialName("thumbnail_url") val thumbnailUrl: String? = null,
    @SerialName("is_pinned") val isPinned: Boolean = false,
    @SerialName("published_at") val publishedAt: String? = null,
    @SerialName("is_read") val isRead: Boolean = false,
    /** 詳細だけに含まれる: Markdown と、それを変換した安全な HTML */
    val body: String? = null,
    @SerialName("body_html") val bodyHtml: String? = null,
)

@Serializable
data class UnreadCount(@SerialName("unread_count") val unreadCount: Int)
