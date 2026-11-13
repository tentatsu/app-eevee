import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    // 画面のスクリーンショットテスト（エミュレーター不要。./gradlew recordRoborazziDebug）
    alias(libs.plugins.roborazzi)
}

// google-services.json があるときだけ Firebase（FCM）を有効にする。なくてもビルド・起動はできる（プッシュは無効）
val hasGoogleServices = file("google-services.json").exists()
if (hasGoogleServices) {
    apply(plugin = libs.plugins.google.services.get().pluginId)
}

// 接続先は local.properties（コミットしない）→ gradle.properties の順に読む
val localProps = Properties().apply {
    val f = rootProject.file("local.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}
fun eeveeProp(key: String): String =
    (localProps.getProperty(key) ?: providers.gradleProperty(key).orNull ?: error("$key is not set")).trim()

android {
    namespace = "com.example.eeveevideo"
    compileSdk = 37

    defaultConfig {
        applicationId = "com.example.eeveevideo"
        minSdk = 26
        targetSdk = 37
        versionCode = 1
        versionName = "1.0.0"

        buildConfigField("String", "EEVEE_BASE_URL", "\"${eeveeProp("eevee.baseUrl").trimEnd('/')}\"")
        buildConfigField("String", "EEVEE_APP_KEY", "\"${eeveeProp("eevee.appKey")}\"")
        buildConfigField("boolean", "FCM_ENABLED", hasGoogleServices.toString())
    }

    buildTypes {
        debug {
            // ローカルの Sail（http）に接続できるように、debug だけ平文通信を許可する
            manifestPlaceholders["networkSecurityConfig"] = "@xml/network_security_config_debug"
        }
        release {
            isMinifyEnabled = false
            manifestPlaceholders["networkSecurityConfig"] = "@xml/network_security_config"
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    testOptions {
        unitTests {
            isIncludeAndroidResources = true
        }
    }

    lint {
        lintConfig = file("lint.xml")
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.datastore.preferences)
    // registerForActivityResult に必要な Fragment 1.3 以上（Firebase などが古い版を引き込むため明示する）
    implementation(libs.androidx.fragment.ktx)

    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons)
    debugImplementation(libs.compose.ui.tooling)

    implementation(libs.media3.exoplayer)
    implementation(libs.media3.exoplayer.hls)
    implementation(libs.media3.ui.compose)

    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)

    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.coroutines.play.services)
    implementation(libs.coil.compose)
    implementation(libs.coil.network)

    testImplementation(libs.junit)
    testImplementation(libs.robolectric)
    testImplementation(libs.roborazzi)
    testImplementation(libs.roborazzi.compose)
    testImplementation(libs.androidx.test.ext.junit)
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(libs.compose.ui.test.manifest)
}
