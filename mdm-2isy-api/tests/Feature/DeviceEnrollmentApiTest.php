<?php

namespace Tests\Feature;

use App\Models\DeviceCredential;
use App\Models\DeviceEnrollmentToken;
use App\Models\DeviceGroup;
use App\Models\Organization;
use App\Models\Profil;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceEnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_consumes_the_token_once_and_only_stores_secret_hashes(): void
    {
        $organization = $this->organization();
        $group = $this->group($organization);
        [$enrollmentToken, $plainTextEnrollmentToken] = $this->enrollmentToken($organization, $group);
        $deviceUid = (string) Str::uuid();

        $response = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $plainTextEnrollmentToken,
            'device_uid' => $deviceUid,
            'imei' => '356789012345678',
            'manufacturer' => 'Blackview',
            'model' => 'Rock 1',
            'android_version' => '13',
            'battery_level' => 82,
            'storage_total_mb' => 128000,
            'storage_free_mb' => 64000,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['device_id', 'device_token']]);

        $deviceToken = $response->json('data.device_token');
        $terminal = Terminal::query()
            ->where('public_id', $response->json('data.device_id'))
            ->sole();
        $credential = DeviceCredential::query()->where('terminal_id', $terminal->id)->sole();

        $this->assertSame($organization->id, $terminal->organization_id);
        $this->assertSame($group->id, $terminal->device_group_id);
        $this->assertSame('enrolled', $terminal->enrollment_status);
        $this->assertSame(hash('sha256', $deviceToken), $credential->token_hash);
        $this->assertNotSame($deviceToken, $credential->token_hash);
        $this->assertNotNull($enrollmentToken->refresh()->used_at);

        $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $plainTextEnrollmentToken,
            'device_uid' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_token');
    }

    public function test_expired_and_revoked_enrollment_tokens_are_rejected(): void
    {
        $organization = $this->organization();
        [, $expiredToken] = $this->enrollmentToken($organization, expiresAt: now()->subMinute());
        [, $revokedToken] = $this->enrollmentToken($organization, revokedAt: now());

        $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $expiredToken,
            'device_uid' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_token');

        $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $revokedToken,
            'device_uid' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_token');
    }

    public function test_duplicate_device_uid_and_imei_are_rejected_without_consuming_the_new_token(): void
    {
        $organization = $this->organization();
        [, $firstToken] = $this->enrollmentToken($organization);
        $deviceUid = (string) Str::uuid();
        $imei = '356789012345679';

        $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $firstToken,
            'device_uid' => $deviceUid,
            'imei' => $imei,
        ])->assertCreated();

        [$uidTokenModel, $uidToken] = $this->enrollmentToken($organization);
        $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $uidToken,
            'device_uid' => $deviceUid,
        ])->assertUnprocessable()->assertJsonValidationErrors('device_uid');
        $this->assertNull($uidTokenModel->refresh()->used_at);

        [$imeiTokenModel, $imeiToken] = $this->enrollmentToken($organization);
        $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $imeiToken,
            'device_uid' => (string) Str::uuid(),
            'imei' => $imei,
        ])->assertUnprocessable()->assertJsonValidationErrors('imei');
        $this->assertNull($imeiTokenModel->refresh()->used_at);
    }

    public function test_heartbeat_requires_a_valid_non_revoked_device_credential_and_updates_inventory(): void
    {
        $organization = $this->organization();
        [, $enrollmentToken] = $this->enrollmentToken($organization);

        $enrollment = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $enrollmentToken,
            'device_uid' => (string) Str::uuid(),
        ])->assertCreated();

        $deviceToken = $enrollment->json('data.device_token');
        $terminal = Terminal::query()
            ->where('public_id', $enrollment->json('data.device_id'))
            ->sole();

        $this->withToken('invalid-device-token')
            ->postJson('/api/v1/device/heartbeat')
            ->assertUnauthorized();

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat', [
                'manufacturer' => 'Blackview',
                'model' => 'Rock 1',
                'android_version' => '13',
                'agent_version' => '1.2.3',
                'battery_level' => 73,
                'storage_total_mb' => 128000,
                'storage_free_mb' => 62000,
                'lat' => 5.3599,
                'lng' => -4.0083,
            ])->assertOk()
            ->assertJsonPath('data.device_id', $terminal->public_id)
            ->assertJsonStructure(['data' => ['server_time']]);

        $terminal->refresh();
        $this->assertSame('Blackview', $terminal->manufacturer);
        $this->assertSame('Rock 1', $terminal->modele);
        $this->assertSame(73, $terminal->batterie);
        $this->assertSame('En ligne', $terminal->statut);
        $this->assertNotNull($terminal->last_seen_at);
        $this->assertNotNull($terminal->credential->last_used_at);

        $terminal->credential->forceFill(['revoked_at' => now()])->save();

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat')
            ->assertUnauthorized();
    }

    public function test_heartbeat_returns_multi_kiosk_applications_from_the_assigned_profile(): void
    {
        $organization = $this->organization();
        [, $enrollmentToken] = $this->enrollmentToken($organization);
        $enrollment = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $enrollmentToken,
            'device_uid' => (string) Str::uuid(),
        ])->assertCreated();
        $terminal = Terminal::query()
            ->where('public_id', $enrollment->json('data.device_id'))
            ->sole();
        $profile = Profil::query()->create([
            'nom' => 'Multi-kiosk warehouse',
            'kiosk' => true,
            'app_kiosk' => 'com.example.primary',
            'kiosk_apps' => [
                'com.example.primary',
                'com.example.scanner',
            ],
        ]);
        $terminal->profil()->associate($profile)->save();

        $this->withToken($enrollment->json('data.device_token'))
            ->postJson('/api/v1/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.policy.kiosk', true)
            ->assertJsonPath('data.policy.app_kiosk', 'com.example.primary')
            ->assertJsonPath('data.policy.kiosk_apps', [
                'com.example.primary',
                'com.example.scanner',
            ]);
    }

    public function test_heartbeat_rejects_identity_or_tenant_changes_and_bounds_telemetry(): void
    {
        $organization = $this->organization();
        [, $enrollmentToken] = $this->enrollmentToken($organization);
        $deviceUid = (string) Str::uuid();

        $enrollment = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $enrollmentToken,
            'device_uid' => $deviceUid,
        ])->assertCreated();

        $deviceToken = $enrollment->json('data.device_token');
        $terminal = Terminal::query()
            ->where('public_id', $enrollment->json('data.device_id'))
            ->sole();

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat', [
                'organization_id' => $this->organization()->id,
                'device_group_id' => 999999,
                'device_uid' => (string) Str::uuid(),
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id', 'device_group_id', 'device_uid']);

        $terminal->refresh();
        $this->assertSame($organization->id, $terminal->organization_id);
        $this->assertSame($deviceUid, $terminal->device_uid);

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat', [
                'battery_level' => 101,
                'latitude' => 91,
                'longitude' => 181,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['battery_level', 'latitude', 'longitude']);

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat', ['lat' => 5.3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lng');

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat', [
                'lat' => 5.3,
                'lng' => -4.0,
                'latitude' => 5.4,
                'longitude' => -4.1,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng', 'latitude', 'longitude']);
    }

    public function test_device_token_cannot_access_a_sanctum_admin_route(): void
    {
        $organization = $this->organization();
        [, $enrollmentToken] = $this->enrollmentToken($organization);

        $enrollment = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $enrollmentToken,
            'device_uid' => (string) Str::uuid(),
        ])->assertCreated();

        $this->withToken($enrollment->json('data.device_token'))
            ->getJson('/api/device-enrollments')
            ->assertUnauthorized();
    }

    private function organization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Organization '.Str::random(8),
            'slug' => 'org-'.Str::lower(Str::random(12)),
            'active' => true,
        ]);
    }

    private function group(Organization $organization): DeviceGroup
    {
        return DeviceGroup::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Group '.Str::random(8),
        ]);
    }

    /**
     * @return array{DeviceEnrollmentToken, string}
     */
    private function enrollmentToken(
        Organization $organization,
        ?DeviceGroup $group = null,
        mixed $expiresAt = null,
        mixed $revokedAt = null,
    ): array {
        $plainTextToken = 'mdm_enroll_'.Str::random(48);
        $token = DeviceEnrollmentToken::query()->create([
            'organization_id' => $organization->id,
            'device_group_id' => $group?->id,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $expiresAt ?? now()->addHour(),
            'revoked_at' => $revokedAt,
        ]);

        return [$token, $plainTextToken];
    }
}
