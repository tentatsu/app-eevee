package com.example.eeveevideo.data

import com.example.eeveevideo.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.logging.HttpLoggingInterceptor
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * app-eevee のアプリ向けAPI（/api/v1/apps/{app_key}/...）のクライアント。
 * 仕様は app-eevee の docs/openapi.json と docs/client-integration.md を参照。
 */
class EeveeApi(private val tokenProvider: () -> String?) {

    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
    private val base = "${BuildConfig.EEVEE_BASE_URL}/api/v1/apps/${BuildConfig.EEVEE_APP_KEY}"

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .apply {
            if (BuildConfig.DEBUG) {
                addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
            }
        }
        .build()

    // ---- 端末 ----

    suspend fun registerDevice(installationId: String, fcmToken: String?, pushPermission: String, appVersion: String, osVersion: String, locale: String, timezone: String) {
        post<JsonObject>("/devices", buildJsonObject {
            put("installation_id", installationId)
            put("platform", "android")
            fcmToken?.let { put("fcm_token", it) }
            put("push_permission", pushPermission)
            put("app_version", appVersion)
            put("os_version", osVersion)
            put("locale", locale)
            put("timezone", timezone)
        })
    }

    // ---- 認証（builtin 方式） ----

    suspend fun register(email: String, password: String, name: String, installationId: String): AuthResult =
        post("/auth/register", buildJsonObject {
            put("email", email); put("password", password); put("name", name); put("installation_id", installationId)
        })

    suspend fun login(email: String, password: String, installationId: String): AuthResult =
        post("/auth/login", buildJsonObject {
            put("email", email); put("password", password); put("installation_id", installationId)
        })

    /** ログアウト。installation_id を渡すと端末とユーザーの紐づけが外れる */
    suspend fun logout(installationId: String) {
        post<JsonObject>("/auth/logout", buildJsonObject { put("installation_id", installationId) })
    }

    // ---- 動画 ----

    /** 動画一覧。category / tag で絞り込める */
    suspend fun videos(category: String? = null, tag: String? = null): Envelope<List<Video>> {
        val query = buildList {
            add("per_page=100")
            category?.let { add("category=" + java.net.URLEncoder.encode(it, "UTF-8")) }
            tag?.let { add("tag=" + java.net.URLEncoder.encode(it, "UTF-8")) }
        }.joinToString("&")
        return getEnvelope("/videos?$query")
    }

    suspend fun video(id: Long): Video = getEnvelope<Video>("/videos/$id").data

    suspend fun play(videoId: Long, installationId: String): PlayResult =
        post("/videos/$videoId/play", buildJsonObject { put("installation_id", installationId) })

    suspend fun heartbeat(sessionId: String, installationId: String): HeartbeatResult =
        post("/playback-sessions/$sessionId/heartbeat", buildJsonObject { put("installation_id", installationId) })

    suspend fun stop(sessionId: String, installationId: String) {
        post<JsonObject>("/playback-sessions/$sessionId/stop", buildJsonObject { put("installation_id", installationId) })
    }

    // ---- お知らせ ----

    /** 一覧（ピン留め → 新しい順）。トークンを付けると、セグメント向けのお知らせと is_read も含む */
    suspend fun notices(page: Int = 1): Envelope<List<Notice>> = getEnvelope("/notices?per_page=50&page=$page")

    suspend fun notice(id: Long): Notice = getEnvelope<Notice>("/notices/$id").data

    /** 既読にする（ログインが必要） */
    suspend fun markNoticeRead(id: Long): UnreadCount = post("/notices/$id/read", buildJsonObject { })

    suspend fun unreadNoticeCount(): Int = getEnvelope<UnreadCount>("/notices/unread-count").data.unreadCount

    // ---- プッシュ ----

    /** 通知のタップ（開封）を記録する。何度呼んでも 1 回として数えられる */
    suspend fun pushOpened(campaignId: Long, installationId: String) {
        post<JsonObject>("/push/opened", buildJsonObject {
            put("campaign_id", campaignId); put("installation_id", installationId)
        })
    }

    // ---- HTTP ----

    private suspend inline fun <reified T> post(path: String, body: JsonObject): T =
        json.decodeFromString<Envelope<T>>(execute("POST", path, body.toString())).data

    private suspend inline fun <reified T> getEnvelope(path: String): Envelope<T> =
        json.decodeFromString<Envelope<T>>(execute("GET", path, null))

    private suspend fun execute(method: String, path: String, body: String?): String = withContext(Dispatchers.IO) {
        val builder = Request.Builder().url(base + path).header("Accept", "application/json")
        tokenProvider()?.let { builder.header("Authorization", "Bearer $it") }
        builder.method(method, body?.toRequestBody("application/json".toMediaType()))

        val response = try {
            client.newCall(builder.build()).execute()
        } catch (e: IOException) {
            throw ApiException(0, "network_error", "サーバーに接続できませんでした")
        }
        response.use {
            val text = it.body.string()
            if (!it.isSuccessful) {
                val error = runCatching { json.decodeFromString<ErrorEnvelope>(text).error }.getOrNull()
                throw ApiException(it.code, error?.code ?: "http_${it.code}", error?.message ?: "エラーが発生しました（HTTP ${it.code}）")
            }
            text
        }
    }
}
