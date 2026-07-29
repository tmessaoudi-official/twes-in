plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.twesin.twes_in"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.twesin.twes_in"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    // Release signing is read from android/key.properties when it exists, and the release build FAILS when
    // it does not.
    //
    // `flutter create` scaffolds `signingConfig = signingConfigs.getByName("debug")` with a TODO. The Android
    // debug keystore is a FIXED, PUBLISHED key (~/.android/debug.keystore, password "android"), so anything
    // signed with it can be re-signed by anybody and accepted by Android as an update to the same app. Google
    // Play rejects debug-signed uploads, but this README explicitly contemplates direct APK and desktop
    // distribution, where nothing rejects it. Certification round 5 filed it; a TODO is not a control.
    //
    // Failing closed rather than silently falling back is the whole point: a fallback would make the
    // dangerous state the DEFAULT and reachable by forgetting a file, which is how debug-signed artifacts
    // ship. `flutter run --debug` and `flutter test` are unaffected — only a release build needs this.
    val keyProperties = java.util.Properties()
    val keyPropertiesFile = rootProject.file("key.properties")

    if (keyPropertiesFile.exists()) {
        keyProperties.load(keyPropertiesFile.inputStream())
    }

    signingConfigs {
        create("release") {
            keyAlias = keyProperties.getProperty("keyAlias")
            keyPassword = keyProperties.getProperty("keyPassword")
            storeFile = keyProperties.getProperty("storeFile")?.let { file(it) }
            storePassword = keyProperties.getProperty("storePassword")
        }
    }

    buildTypes {
        release {
            signingConfig = if (keyPropertiesFile.exists()) {
                signingConfigs.getByName("release")
            } else {
                // No key.properties: leave the release variant UNSIGNED so the build fails loudly at the
                // signing step, instead of quietly producing an artifact signed with a key the whole world
                // has. See mobile/README.md for what key.properties must contain.
                null
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
