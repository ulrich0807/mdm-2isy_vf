package com.mdm2isy.agent.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.CancellationSignal
import android.os.Handler
import android.os.Looper
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference

object LocationErrorCodes {
    const val PERMISSION_DENIED = "LOCATION_PERMISSION_DENIED"
    const val FINE_PERMISSION_REQUIRED = "LOCATION_FINE_PERMISSION_REQUIRED"
    const val DISABLED = "LOCATION_DISABLED"
    const val UNAVAILABLE = "LOCATION_UNAVAILABLE"
    const val TIMEOUT = "LOCATION_TIMEOUT"
    const val REQUEST_FAILED = "LOCATION_REQUEST_FAILED"
}

data class DeviceLocation(
    val latitude: Double,
    val longitude: Double,
    val accuracyMeters: Double?,
    val capturedAtEpochMillis: Long,
)

sealed interface DeviceLocationResult {
    data class Success(val location: DeviceLocation) : DeviceLocationResult

    data class Failure(
        val errorCode: String,
        val errorMessage: String,
    ) : DeviceLocationResult
}

fun interface CancellableLocationRequest {
    fun cancel()
}

sealed interface LocationRequestStart {
    data class Started(val request: CancellableLocationRequest) : LocationRequestStart

    data class Rejected(
        val errorCode: String,
        val errorMessage: String,
    ) : LocationRequestStart
}

interface CurrentLocationSource {
    fun request(
        highAccuracy: Boolean,
        callback: (DeviceLocation?) -> Unit,
    ): LocationRequestStart
}

fun interface LocationTimeoutScheduler {
    fun schedule(delayMillis: Long, task: () -> Unit): CancellableLocationRequest
}

class HandlerLocationTimeoutScheduler(
    private val handler: Handler = Handler(Looper.getMainLooper()),
) : LocationTimeoutScheduler {
    override fun schedule(
        delayMillis: Long,
        task: () -> Unit,
    ): CancellableLocationRequest {
        val runnable = Runnable(task)
        handler.postDelayed(runnable, delayMillis)
        return CancellableLocationRequest { handler.removeCallbacks(runnable) }
    }
}

class AndroidCurrentLocationSource(
    context: Context,
) : CurrentLocationSource {
    private val applicationContext = context.applicationContext
    private val locationManager =
        requireNotNull(applicationContext.getSystemService(LocationManager::class.java)) {
            "LocationManager is unavailable on this device."
        }

    @SuppressLint("MissingPermission")
    override fun request(
        highAccuracy: Boolean,
        callback: (DeviceLocation?) -> Unit,
    ): LocationRequestStart {
        val fineGranted = applicationContext.checkSelfPermission(
            Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED
        val coarseGranted = applicationContext.checkSelfPermission(
            Manifest.permission.ACCESS_COARSE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED

        if (!fineGranted && !coarseGranted) {
            return LocationRequestStart.Rejected(
                LocationErrorCodes.PERMISSION_DENIED,
                "La permission de localisation n'est pas accordee.",
            )
        }

        if (highAccuracy && !fineGranted) {
            return LocationRequestStart.Rejected(
                LocationErrorCodes.FINE_PERMISSION_REQUIRED,
                "La localisation precise exige ACCESS_FINE_LOCATION.",
            )
        }

        val locationEnabled = try {
            locationManager.isLocationEnabled
        } catch (_: SecurityException) {
            return LocationRequestStart.Rejected(
                LocationErrorCodes.PERMISSION_DENIED,
                "Android interdit la verification de la localisation.",
            )
        } catch (_: RuntimeException) {
            return LocationRequestStart.Rejected(
                LocationErrorCodes.REQUEST_FAILED,
                "Impossible de verifier l'etat de la localisation.",
            )
        }

        if (!locationEnabled) {
            return LocationRequestStart.Rejected(
                LocationErrorCodes.DISABLED,
                "La localisation est desactivee sur le terminal.",
            )
        }

        val provider = selectProvider(highAccuracy, fineGranted)
            ?: return LocationRequestStart.Rejected(
                LocationErrorCodes.UNAVAILABLE,
                "Aucun fournisseur de localisation compatible n'est disponible.",
            )
        val cancellationSignal = CancellationSignal()

        return try {
            locationManager.getCurrentLocation(
                provider,
                cancellationSignal,
                applicationContext.mainExecutor,
            ) { location ->
                callback(location?.toDeviceLocation())
            }
            LocationRequestStart.Started(
                CancellableLocationRequest { cancellationSignal.cancel() },
            )
        } catch (_: SecurityException) {
            LocationRequestStart.Rejected(
                LocationErrorCodes.PERMISSION_DENIED,
                "Android a refuse l'acces a la localisation.",
            )
        } catch (_: RuntimeException) {
            LocationRequestStart.Rejected(
                LocationErrorCodes.REQUEST_FAILED,
                "Android n'a pas pu demarrer la localisation.",
            )
        }
    }

    private fun selectProvider(
        highAccuracy: Boolean,
        fineGranted: Boolean,
    ): String? {
        val candidates = if (highAccuracy) {
            listOf(
                LocationManager.FUSED_PROVIDER,
                LocationManager.GPS_PROVIDER,
                LocationManager.NETWORK_PROVIDER,
            )
        } else {
            buildList {
                add(LocationManager.FUSED_PROVIDER)
                add(LocationManager.NETWORK_PROVIDER)
                if (fineGranted) {
                    add(LocationManager.GPS_PROVIDER)
                }
            }
        }

        return candidates.firstOrNull { provider ->
            try {
                locationManager.isProviderEnabled(provider)
            } catch (_: RuntimeException) {
                false
            }
        }
    }

    private fun Location.toDeviceLocation(): DeviceLocation = DeviceLocation(
        latitude = latitude,
        longitude = longitude,
        accuracyMeters = accuracy
            .takeIf { hasAccuracy() && it.isFinite() && it in 0f..100_000f }
            ?.toDouble(),
        capturedAtEpochMillis = time,
    )
}

class DeviceLocationProvider(
    private val source: CurrentLocationSource,
    private val timeoutScheduler: LocationTimeoutScheduler,
) {
    constructor(context: Context) : this(
        source = AndroidCurrentLocationSource(context),
        timeoutScheduler = HandlerLocationTimeoutScheduler(),
    )

    fun locate(
        timeoutMillis: Long,
        highAccuracy: Boolean,
        callback: (DeviceLocationResult) -> Unit,
    ): CancellableLocationRequest {
        require(timeoutMillis > 0L) { "timeoutMillis must be positive." }

        val completed = AtomicBoolean(false)
        val sourceRequest = AtomicReference<CancellableLocationRequest?>()
        val timeoutRequest = AtomicReference<CancellableLocationRequest?>()

        fun complete(result: DeviceLocationResult) {
            if (completed.compareAndSet(false, true)) {
                sourceRequest.get()?.cancel()
                timeoutRequest.get()?.cancel()
                callback(result)
            }
        }

        val start = try {
            source.request(highAccuracy) { location ->
                complete(location.toDeviceLocationResult())
            }
        } catch (_: SecurityException) {
            LocationRequestStart.Rejected(
                LocationErrorCodes.PERMISSION_DENIED,
                "Android a refuse l'acces a la localisation.",
            )
        } catch (_: RuntimeException) {
            LocationRequestStart.Rejected(
                LocationErrorCodes.REQUEST_FAILED,
                "La demande de localisation a echoue.",
            )
        }

        when (start) {
            is LocationRequestStart.Rejected -> {
                complete(
                    DeviceLocationResult.Failure(
                        start.errorCode,
                        start.errorMessage,
                    ),
                )
            }

            is LocationRequestStart.Started -> {
                sourceRequest.set(start.request)
                if (completed.get()) {
                    start.request.cancel()
                } else {
                    val timeout = try {
                        timeoutScheduler.schedule(timeoutMillis) {
                            complete(
                                DeviceLocationResult.Failure(
                                    LocationErrorCodes.TIMEOUT,
                                    "La localisation n'a pas repondu avant l'expiration du delai.",
                                ),
                            )
                        }
                    } catch (_: RuntimeException) {
                        complete(
                            DeviceLocationResult.Failure(
                                LocationErrorCodes.REQUEST_FAILED,
                                "Le delai de localisation n'a pas pu etre programme.",
                            ),
                        )
                        null
                    }
                    if (timeout != null) {
                        timeoutRequest.set(timeout)
                        if (completed.get()) {
                            timeout.cancel()
                        }
                    }
                }
            }
        }

        return CancellableLocationRequest {
            if (completed.compareAndSet(false, true)) {
                sourceRequest.get()?.cancel()
                timeoutRequest.get()?.cancel()
            }
        }
    }

    private fun DeviceLocation?.toDeviceLocationResult(): DeviceLocationResult {
        if (this == null || !latitude.isFinite() || !longitude.isFinite()) {
            return DeviceLocationResult.Failure(
                LocationErrorCodes.UNAVAILABLE,
                "Android n'a retourne aucune position exploitable.",
            )
        }

        if (latitude !in -90.0..90.0 || longitude !in -180.0..180.0) {
            return DeviceLocationResult.Failure(
                LocationErrorCodes.UNAVAILABLE,
                "Android a retourne des coordonnees hors limites.",
            )
        }

        return DeviceLocationResult.Success(
            DeviceLocation(
                latitude = latitude,
                longitude = longitude,
                accuracyMeters = accuracyMeters
                    ?.takeIf { it.isFinite() && it in 0.0..100_000.0 },
                capturedAtEpochMillis = capturedAtEpochMillis,
            ),
        )
    }
}
