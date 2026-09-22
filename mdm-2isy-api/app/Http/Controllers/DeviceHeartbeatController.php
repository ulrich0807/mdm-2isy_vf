<?php

namespace App\Http\Controllers;

use App\Models\App as ManagedApp;
use App\Models\Terminal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceHeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        if (! $device instanceof Terminal) {
            return response()->json(['message' => 'Unauthenticated device.'], 401);
        }

        $input = $request->all();
        $hasShortCoordinates = array_key_exists('lat', $input) || array_key_exists('lng', $input);
        $hasLongCoordinates = array_key_exists('latitude', $input) || array_key_exists('longitude', $input);

        if ($hasShortCoordinates && $hasLongCoordinates) {
            $coordinateMessage = 'Use either lat/lng or latitude/longitude, not both.';

            throw ValidationException::withMessages([
                'lat' => $coordinateMessage,
                'lng' => $coordinateMessage,
                'latitude' => $coordinateMessage,
                'longitude' => $coordinateMessage,
            ]);
        }

        $data = $request->validate([
            'organization_id' => ['prohibited'],
            'device_group_id' => ['prohibited'],
            'device_uid' => ['prohibited'],
            'imei' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('terminals', 'imei')->ignore($device->id),
            ],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'android_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'android_build' => ['sometimes', 'nullable', 'string', 'max:255'],
            'agent_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'battery_level' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'storage_total_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'storage_free_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lat' => [
                'required_with:lng',
                'numeric',
                'between:-90,90',
            ],
            'lng' => [
                'required_with:lat',
                'numeric',
                'between:-180,180',
            ],
            'latitude' => [
                'required_with:longitude',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'required_with:latitude',
                'numeric',
                'between:-180,180',
            ],
            'fcm_token' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'installed_apps' => ['sometimes', 'nullable', 'array'],
        ]);

        $this->validateStorage($device, $data);

        $attributes = [];
        $fieldMap = [
            'imei' => 'imei',
            'serial_number' => 'serial_number',
            'manufacturer' => 'manufacturer',
            'model' => 'modele',
            'android_version' => 'android_version',
            'android_build' => 'android_build',
            'agent_version' => 'agent_version',
            'battery_level' => 'batterie',
            'storage_total_mb' => 'storage_total_mb',
            'storage_free_mb' => 'storage_free_mb',
            'lat' => 'lat',
            'lng' => 'lng',
            'latitude' => 'lat',
            'longitude' => 'lng',
            'fcm_token' => 'fcm_token',
            'installed_apps' => 'installed_apps',
        ];

        foreach ($fieldMap as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        $serverTime = now();
        $attributes['last_seen_at'] = $serverTime;
        $attributes['statut'] = 'En ligne';

        try {
            $device->forceFill($attributes)->save();
            
            if (isset($attributes['lat']) && isset($attributes['lng'])) {
                $lastLocation = $device->locationHistories()->latest('recorded_at')->first();
                if (!$lastLocation || $lastLocation->lat != $attributes['lat'] || $lastLocation->lng != $attributes['lng']) {
                    $device->locationHistories()->create([
                        'lat' => $attributes['lat'],
                        'lng' => $attributes['lng'],
                        'recorded_at' => $serverTime,
                    ]);
                }
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'imei' => 'This IMEI is already enrolled.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'device_id' => $device->public_id,
                'server_time' => $serverTime->toIso8601String(),
                'policy' => $this->policyFor($device),
            ],
        ]);
    }

    /**
     * Merge organization-wide application blocks with the assigned profile.
     * A blacklisted application therefore applies at the next heartbeat even
     * when the terminal has no explicit profile.
     *
     * @return array<string, mixed>|null
     */
    private function policyFor(Terminal $device): ?array
    {
        $profile = $device->profil;
        $organizationBlacklist = ManagedApp::query()
            ->where('organization_id', $device->organization_id)
            ->where('type', 'noire')
            ->pluck('pkg')
            ->all();
        $blacklist = array_values(array_unique(array_filter(array_merge(
            $profile?->blacklist_apps ?? [],
            $organizationBlacklist,
        ))));

        if (! $profile && $blacklist === []) {
            return null;
        }

        return [
            'kiosk' => (bool) ($profile?->kiosk ?? false),
            'app_kiosk' => $profile?->app_kiosk,
            'kiosk_apps' => $profile?->kiosk_apps ?? [],
            'no_cam' => (bool) ($profile?->no_cam ?? false),
            'no_usb' => (bool) ($profile?->no_usb ?? false),
            'no_bt' => (bool) ($profile?->no_bt ?? false),
            'no_wifi' => (bool) ($profile?->no_wifi ?? false),
            'no_data' => (bool) ($profile?->no_data ?? false),
            'no_airplane' => (bool) ($profile?->no_airplane ?? false),
            'pin_fort' => (bool) ($profile?->pin_fort ?? false),
            'blacklist_apps' => $blacklist,
            'whitelist_apps' => $profile?->whitelist_apps ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateStorage(Terminal $device, array $data): void
    {
        $total = array_key_exists('storage_total_mb', $data)
            ? $data['storage_total_mb']
            : $device->storage_total_mb;
        $free = array_key_exists('storage_free_mb', $data)
            ? $data['storage_free_mb']
            : $device->storage_free_mb;

        if ($total !== null && $free !== null && $free > $total) {
            throw ValidationException::withMessages([
                'storage_free_mb' => 'The free storage cannot exceed total storage.',
            ]);
        }
    }
}
