<?php

namespace App\Http\Controllers;

use App\Models\DeviceCredential;
use App\Models\DeviceEnrollmentToken;
use App\Models\Organization;
use App\Models\Terminal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DeviceEnrollmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required_without:token', 'string', 'max:512'],
            'token' => ['required_without:enrollment_token', 'string', 'max:512'],
            'device_uid' => ['required', 'uuid'],
            'imei' => ['nullable', 'string', 'max:64'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'android_version' => ['nullable', 'string', 'max:100'],
            'android_build' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['nullable', 'string', 'max:100'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'storage_total_mb' => ['nullable', 'integer', 'min:0'],
            'storage_free_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->validateStorage($data);

        $data['token'] = $data['enrollment_token'] ?? $data['token'];
        $data['device_uid'] = Str::lower($data['device_uid']);

        try {
            $result = DB::transaction(function () use ($data): array {
                $enrollmentToken = DeviceEnrollmentToken::query()
                    ->where('token_hash', hash('sha256', $data['token']))
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $enrollmentToken
                    || $enrollmentToken->used_at !== null
                    || $enrollmentToken->revoked_at !== null
                    || $enrollmentToken->expires_at->lte(now())
                    || ! Organization::query()
                        ->whereKey($enrollmentToken->organization_id)
                        ->where('active', true)
                        ->exists()
                ) {
                    $this->invalidToken();
                }

                $this->ensureDeviceIsUnique($enrollmentToken->organization_id, $data);

                $timestamp = now();
                $terminal = null;

                if (isset($data['imei'])) {
                    $terminal = Terminal::query()
                        ->where('imei', $data['imei'])
                        ->where('enrollment_status', 'pending')
                        ->first();
                }

                if (!$terminal) {
                    $terminal = new Terminal;
                    $terminal->public_id = (string) Str::uuid();
                }

                $terminal->forceFill([
                    'organization_id' => $enrollmentToken->organization_id,
                    'device_group_id' => $terminal->device_group_id ?? $enrollmentToken->device_group_id,
                    'device_uid' => $data['device_uid'],
                    'imei' => $data['imei'] ?? null,
                    'serial_number' => $data['serial_number'] ?? null,
                    'manufacturer' => $data['manufacturer'] ?? null,
                    'modele' => $data['model'] ?? null,
                    'android_version' => $data['android_version'] ?? null,
                    'android_build' => $data['android_build'] ?? null,
                    'agent_version' => $data['agent_version'] ?? null,
                    'batterie' => $data['battery_level'] ?? null,
                    'storage_total_mb' => $data['storage_total_mb'] ?? null,
                    'storage_free_mb' => $data['storage_free_mb'] ?? null,
                    'enrollment_status' => 'enrolled',
                    'enrolled_at' => $timestamp,
                    'last_seen_at' => $timestamp,
                    'statut' => 'En ligne',
                ])->save();

                $deviceToken = 'mdm_device_'.Str::random(64);
                $credential = new DeviceCredential;
                $credential->forceFill([
                    'terminal_id' => $terminal->id,
                    'token_hash' => hash('sha256', $deviceToken),
                ])->save();

                $enrollmentToken->forceFill(['used_at' => $timestamp])->save();

                return [
                    'device_id' => $terminal->public_id,
                    'device_token' => $deviceToken,
                ];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'device_uid' => 'The device UID or IMEI is already enrolled.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateStorage(array $data): void
    {
        if (
            isset($data['storage_total_mb'], $data['storage_free_mb'])
            && $data['storage_free_mb'] > $data['storage_total_mb']
        ) {
            throw ValidationException::withMessages([
                'storage_free_mb' => 'The free storage cannot exceed total storage.',
            ]);
        }
    }

    private function ensureDeviceIsUnique(int $organizationId, array $data): void
    {
        if (Terminal::query()
            ->where('organization_id', $organizationId)
            ->where('device_uid', $data['device_uid'])
            ->exists()) {
            throw ValidationException::withMessages([
                'device_uid' => 'This device UID is already enrolled.',
            ]);
        }

        if (isset($data['imei'])) {
            $existing = Terminal::query()->where('imei', $data['imei'])->first();
            if ($existing && $existing->enrollment_status !== 'pending') {
                throw ValidationException::withMessages([
                    'imei' => 'This IMEI is already enrolled and active.',
                ]);
            }
        }
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages([
            'enrollment_token' => 'The enrollment token is invalid or unavailable.',
        ]);
    }
}
