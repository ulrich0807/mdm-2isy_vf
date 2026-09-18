plugins {
    id("com.android.application")
    id("com.google.gms.google-services")
}

android {
    namespace = "com.mdm2isy.agent"
    compileSdk = 37

    defaultConfig {
        applicationId = "com.mdm2isy.agent"
        minSdk = 33
        targetSdk = 36
        versionCode = 1
        versionName = "0.1.0"

        testInstrumentationRunner = "android.test.InstrumentationTestRunner"
        buildConfigField("String", "DEFAULT_API_URL", "\"https://api.mdm-2isy.com/api/v1/device\"")
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

dependencies {
    implementation("com.google.firebase:firebase-messaging:24.0.0")
    testImplementation("junit:junit:4.13.2")
}
