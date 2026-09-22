plugins {
    id("com.android.application")
    id("com.google.gms.google-services")
}

val releaseKeystoreFile = providers.environmentVariable("MDM_ANDROID_KEYSTORE_FILE").orNull
val releaseKeystorePassword = providers.environmentVariable("MDM_ANDROID_KEYSTORE_PASSWORD").orNull
val releaseKeyAlias = providers.environmentVariable("MDM_ANDROID_KEY_ALIAS").orNull
val releaseKeyPassword = providers.environmentVariable("MDM_ANDROID_KEY_PASSWORD").orNull
val hasReleaseSigning = listOf(
    releaseKeystoreFile,
    releaseKeystorePassword,
    releaseKeyAlias,
    releaseKeyPassword,
).all { !it.isNullOrBlank() }

android {
    namespace = "com.mdm2isy.agent"
    compileSdk = 37

    defaultConfig {
        applicationId = "com.mdm2isy.agent"
        minSdk = 33
        targetSdk = 36
        versionCode = 7
        versionName = "0.1.6"

        testInstrumentationRunner = "android.test.InstrumentationTestRunner"
        buildConfigField("String", "DEFAULT_API_URL", "\"https://api.mdm-2isy.com/api/v1/device\"")
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile = file(requireNotNull(releaseKeystoreFile))
                storePassword = releaseKeystorePassword
                keyAlias = releaseKeyAlias
                keyPassword = releaseKeyPassword
            }
        }
    }

    buildTypes {
        debug {
            versionNameSuffix = "-debug"
            buildConfigField(
                "String",
                "DEFAULT_API_URL",
                "\"https://api.mdm-2isy.com/api/v1/device\"",
            )
        }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            if (hasReleaseSigning) {
                signingConfig = signingConfigs.getByName("release")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

}

tasks.matching { it.name == "assembleRelease" || it.name == "bundleRelease" }.configureEach {
    doFirst {
        check(hasReleaseSigning) {
            "Release signing is not configured. Set MDM_ANDROID_KEYSTORE_FILE, " +
                "MDM_ANDROID_KEYSTORE_PASSWORD, MDM_ANDROID_KEY_ALIAS and MDM_ANDROID_KEY_PASSWORD."
        }
        check(file(requireNotNull(releaseKeystoreFile)).isFile) {
            "The release keystore file does not exist: $releaseKeystoreFile"
        }
    }
}

dependencies {
    implementation("com.google.firebase:firebase-messaging:24.0.0")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
}
