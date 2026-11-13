package com.example.eeveevideo

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import com.example.eeveevideo.data.AppContainer

class EeveeVideoApp : Application() {

    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)

        // FCM の通知を表示するチャンネル（Android 8 以上で必須）
        val channel = NotificationChannel(
            getString(R.string.notification_channel_id),
            getString(R.string.notification_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        )
        getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }
}
