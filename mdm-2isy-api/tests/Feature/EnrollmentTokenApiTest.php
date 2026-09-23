<?php

namespace Tests\Feature;

use App\Models\DeviceEnrollmentToken;
use App\Models\DeviceGroup;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_a_hashed_token_for_its_organization_without_leaking_it_in_lists(): void
    {
        $organization = $this->organization();
        $group = $this->group($organization);
        $admin = $this->user('admin', $organization);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/device-enrollments', [
            'device_group_id' => $group->id,
            'label' => 'Warehouse enrollment',
            'expires_in_minutes' => 60,
        ])->assertCreated()
            ->assertJsonMissingPath('data.token_hash');

        $plainTextToken = $response->json('data.enrollment_token');
        $this->assertIsString($plainTextToken);
        $this->assertMatchesRegularExpression('/\A\d{6}\z/', $plainTextToken);

        $storedToken = DeviceEnrollmentToken::query()->sole();
        $this->assertSame(hash('sha256', $plainTextToken), $storedToken->token_hash);
        $this->assertNotSame($plainTextToken, $storedToken->token_hash);
        $this->assertSame($organization->id, $storedToken->organization_id);
        $this->assertSame($group->id, $storedToken->device_group_id);

        $response->assertJsonPath('data.enrollment_payload.version', 1)
            ->assertJsonPath('data.enrollment_payload.token', $plainTextToken)
            ->assertJsonStructure(['data' => ['enrollment_payload' => ['api_url']]]);
        $this->assertSame(
            url('/download/mdm-agent.apk'),
            $response->json('data.device_owner_qr_payload')[
                'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION'
            ],
        );
        $this->assertSame(
            rtrim(strtr(base64_encode(hash_file('sha256', public_path('apk/mdm-agent.apk'), true)), '+/', '-_'), '='),
            $response->json('data.device_owner_qr_payload')[
                'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM'
            ],
        );

        $this->getJson('/api/device-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['token' => $plainTextToken])
            ->assertJsonMissing(['token_hash' => $storedToken->token_hash]);
    }

    public function test_token_duration_is_limited_to_the_supported_window(): void
    {
        $organization = $this->organization();
        Sanctum::actingAs($this->user('admin', $organization));

        $this->postJson('/api/device-enrollments', [
            'expires_in_minutes' => 4,
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_in_minutes');

        $this->postJson('/api/device-enrollments', [
            'expires_in_minutes' => 10081,
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_in_minutes');
    }

    public function test_admin_is_tenant_scoped_and_super_admin_can_select_an_organization(): void
    {
        $organizationA = $this->organization();
        $organizationB = $this->organization();
        $groupB = $this->group($organizationB);
        $adminA = $this->user('admin', $organizationA);
        $superAdmin = $this->user('super_admin');

        Sanctum::actingAs($adminA);

        $this->postJson('/api/device-enrollments', [
            'organization_id' => $organizationB->id,
            'expires_in_minutes' => 60,
        ])->assertForbidden();

        $this->postJson('/api/device-enrollments', [
            'device_group_id' => $groupB->id,
            'expires_in_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('device_group_id');

        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/device-enrollments', [
            'expires_in_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->postJson('/api/device-enrollments', [
            'organization_id' => $organizationB->id,
            'device_group_id' => $groupB->id,
            'expires_in_minutes' => 60,
        ])->assertCreated();

        Sanctum::actingAs($adminA);
        $this->getJson('/api/device-enrollments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->user('admin', $organizationB));
        $this->getJson('/api/device-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_token_revocation_is_tenant_scoped_and_non_destructive(): void
    {
        $organizationA = $this->organization();
        $organizationB = $this->organization();
        $token = $this->storedToken($organizationB);

        Sanctum::actingAs($this->user('admin', $organizationA));
        $this->deleteJson("/api/device-enrollments/{$token->public_id}")
            ->assertForbidden();

        Sanctum::actingAs($this->user('super_admin'));
        $this->deleteJson("/api/device-enrollments/{$token->public_id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => null]);

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertDatabaseHas('device_enrollment_tokens', ['id' => $token->id]);
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

    private function user(string $role, ?Organization $organization = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'organization_id' => $organization?->id,
        ]);
    }

    private function storedToken(Organization $organization): DeviceEnrollmentToken
    {
        return DeviceEnrollmentToken::query()->create([
            'organization_id' => $organization->id,
            'token_hash' => hash('sha256', 'unused-'.Str::random(32)),
            'expires_at' => now()->addHour(),
        ]);
    }
}
