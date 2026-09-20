package com.mdm2isy.agent.model

import org.json.JSONObject

data class AppInfo(
    val name: String,
    val packageName: String
) {
    fun toJson(): JSONObject = JSONObject().apply {
        put("name", name)
        put("packageName", packageName)
    }
}
