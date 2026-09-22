<?php

namespace Tests\Feature;

use App\Models\DeviceCredential;
use App\Models\Lic;
use App\Models\Organization;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_is_read_only_and_operator_can_run_non_destructive_commands(): void
    {
        $organization = $this->organization('roles');
        $terminal = Terminal::create([
            'organization_id' => $organization->id,
            'modele' => 'Rock 1 Pro',
            'enrollment_status' => 'enrolled',
            'management_state' => 'active',
        ]);
        DeviceCredential::create([
            'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', 'role-device-token'),
        ]);
        Lic::create([
            'organization_id' => $organization->id,
            'term_id' => $terminal->id,
            'cle' => 'MDM-ROLE-ACCESS',
            'statut' => 'Active',
            'exp_le' => now()->addYear(),
        ]);

        $viewer = User::factory()->create(['role' => 'viewer', 'organization_id' => $organization->id]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/terminals')->assertOk();
        $this->postJson('/api/device-groups', ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson("/api/terminals/{$terminal->id}/lock")->assertForbidden();

        $operator = User::factory()->create(['role' => 'operator', 'organization_id' => $organization->id]);
        Sanctum::actingAs($operator);
        $this->postJson("/api/terminals/{$terminal->id}/lock")->assertCreated();
        $this->postJson("/api/terminals/{$terminal->id}/wipe")->assertForbidden();
    }

    public function test_admin_manages_only_non_admin_users_in_its_organization(): void
    {
        $organization = $this->organization('user-management');
        $admin = User::factory()->create(['role' => 'admin', 'organization_id' => $organization->id]);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/organization-users', [
            'name' => 'Opérateur Terrain',
            'email' => 'operator@example.test',
            'role' => 'operator',
            'password' => 'StrongPassword123',
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.role', 'operator');

        $this->postJson('/api/organization-users', [
            'name' => 'Other Admin',
            'email' => 'admin2@example.test',
            'role' => 'admin',
            'password' => 'StrongPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->getJson('/api/organization-users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'operator@example.test']);

        $operatorId = $created->json('data.id');
        $this->deleteJson("/api/organization-users/{$operatorId}")->assertOk();
    }

    private function organization(string $slug): Organization
    {
        return Organization::create(['name' => ucfirst($slug), 'slug' => $slug, 'active' => true]);
    }
}
