<?php

namespace Tests\Feature;

use App\Models\DeviceCredential;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\App;
use App\Models\Lic;
use App\Models\Log;
use App\Models\Organization;
use App\Models\Profil;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_only_lists_terminals_from_its_organization(): void
    {
        $first = $this->organization('first');
        $second = $this->organization('second');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $first->id,
        ]);
        $visible = $this->terminal($first, 'Visible');
        $this->terminal($second, 'Hidden');

        Sanctum::actingAs($admin);

        $this->getJson('/api/terminals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $visible->public_id)
            ->assertJsonPath('data.0.connectivity_status', 'offline');

        $this->getJson("/api/terminals?organization_id={$second->id}")
            ->assertForbidden();
    }

    public function test_super_admin_can_filter_the_fleet_by_organization(): void
    {
        $first = $this->organization('first');
        $second = $this->organization('second');
        $this->terminal($first, 'First');
        $expected = $this->terminal($second, 'Second');
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

        $this->getJson("/api/terminals?organization_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $expected->public_id);
    }

    public function test_admin_cannot_create_or_delete_a_group_in_another_organization(): void
    {
        $first = $this->organization('first');
        $second = $this->organization('second');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $first->id,
        ]);
        $foreignGroup = DeviceGroup::create([
            'organization_id' => $second->id,
            'name' => 'Foreign',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/device-groups', [
            'organization_id' => $second->id,
            'name' => 'Forbidden',
        ])->assertForbidden();

        $this->postJson('/api/device-groups', ['name' => 'Livreurs'])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $first->id);

        $this->deleteJson("/api/device-groups/{$foreignGroup->id}")
            ->assertForbidden();
    }

    public function test_command_shortcuts_queue_without_changing_device_state_or_crossing_tenants(): void
    {
        $first = $this->organization('first');
        $second = $this->organization('second');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $first->id,
        ]);
        $ownTerminal = $this->terminal($first, 'Own');
        $foreignTerminal = $this->terminal($second, 'Foreign');
        DeviceCredential::create([
            'terminal_id' => $ownTerminal->id,
            'token_hash' => hash('sha256', 'tenant-command-device-token'),
        ]);
        Lic::query()->create([
            'cle' => 'MDM-TEST-TENANT-COMMAND',
            'term_id' => $ownTerminal->id,
            'statut' => 'Active',
            'exp_le' => now()->addYear(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/terminals/{$ownTerminal->id}/lock")
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'lock')
            ->assertJsonPath('data.status', 'queued');
        $this->assertSame('Hors ligne', $ownTerminal->fresh()->statut);
        $this->assertDatabaseHas('device_commands', [
            'terminal_id' => $ownTerminal->id,
            'type' => 'lock',
            'status' => 'queued',
        ]);

        $this->postJson("/api/terminals/{$foreignTerminal->id}/wipe")
            ->assertNotFound();
    }

    public function test_admin_can_only_assign_a_terminal_to_a_group_from_the_same_organization(): void
    {
        $first = $this->organization('group-first');
        $second = $this->organization('group-second');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $first->id,
        ]);
        $terminal = $this->terminal($first, 'Grouped');
        $ownGroup = DeviceGroup::create([
            'organization_id' => $first->id,
            'name' => 'Own group',
        ]);
        $foreignGroup = DeviceGroup::create([
            'organization_id' => $second->id,
            'name' => 'Foreign group',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/terminals/{$terminal->id}/group", [
            'device_group_id' => $foreignGroup->id,
        ])->assertNotFound();

        $this->putJson("/api/terminals/{$terminal->id}/group", [
            'device_group_id' => $ownGroup->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.device_group.id', $ownGroup->id);

        $this->assertDatabaseHas('terminals', [
            'id' => $terminal->id,
            'device_group_id' => $ownGroup->id,
        ]);
    }

    public function test_admin_only_sees_legacy_resources_from_its_organization(): void
    {
        $first = $this->organization('resources-first');
        $second = $this->organization('resources-second');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $first->id,
        ]);
        App::create(['organization_id' => $first->id, 'nom' => 'Visible', 'pkg' => 'com.visible', 'type' => 'blanche']);
        App::create(['organization_id' => $second->id, 'nom' => 'Hidden', 'pkg' => 'com.hidden', 'type' => 'blanche']);
        Profil::create(['organization_id' => $first->id, 'nom' => 'Visible profile']);
        Profil::create(['organization_id' => $second->id, 'nom' => 'Hidden profile']);
        Log::create(['organization_id' => $first->id, 'usr' => 'Visible', 'act' => 'Test', 'typ' => 'primary']);
        Log::create(['organization_id' => $second->id, 'usr' => 'Hidden', 'act' => 'Test', 'typ' => 'primary']);
        Lic::create(['organization_id' => $first->id, 'cle' => 'MDM-VISIBLE', 'statut' => 'Vierge']);
        Lic::create(['organization_id' => $second->id, 'cle' => 'MDM-HIDDEN', 'statut' => 'Vierge']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/apps')->assertOk()->assertJsonCount(1)->assertJsonPath('0.nom', 'Visible');
        $this->getJson('/api/profils')->assertOk()->assertJsonCount(1)->assertJsonPath('0.nom', 'Visible profile');
        $this->getJson('/api/logs')->assertOk()->assertJsonCount(1)->assertJsonPath('0.usr', 'Visible');
        $this->getJson('/api/lics')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.cle', 'MDM-VISIBLE');
    }

    public function test_admin_cannot_delete_or_assign_resources_across_organizations(): void
    {
        $first = $this->organization('mutation-first');
        $second = $this->organization('mutation-second');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $first->id,
        ]);
        $terminal = $this->terminal($first, 'Own terminal');
        $foreignTerminal = $this->terminal($second, 'Foreign terminal');
        $foreignApp = App::create(['organization_id' => $second->id, 'nom' => 'Foreign', 'pkg' => 'com.foreign', 'type' => 'noire']);
        $ownProfile = Profil::create(['organization_id' => $first->id, 'nom' => 'Own profile']);
        $foreignProfile = Profil::create(['organization_id' => $second->id, 'nom' => 'Foreign profile']);
        $licence = Lic::create([
            'organization_id' => $first->id,
            'cle' => 'MDM-OWN-LICENCE',
            'statut' => 'Active',
            'exp_le' => now()->addYear(),
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/apps/{$foreignApp->id}")->assertNotFound();
        $this->putJson("/api/terminals/{$terminal->id}/profil", ['profil_id' => $foreignProfile->id])->assertNotFound();
        $this->putJson("/api/terminals/{$terminal->id}/profil", ['profil_id' => $ownProfile->id])->assertOk();
        $this->postJson("/api/lics/{$licence->id}/assign", ['term_id' => $foreignTerminal->id])->assertNotFound();
    }

    public function test_admin_uploads_an_apk_inside_its_organization(): void
    {
        Storage::fake('local');
        $organization = $this->organization('application-owner');
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'organization_id' => $organization->id,
        ]));

        $response = $this->post('/api/apps', [
            'nom' => 'Métier',
            'pkg' => 'com.example.metier',
            'type' => 'blanche',
            'chemin_apk' => UploadedFile::fake()->create(
                'metier.apk',
                100,
                'application/vnd.android.package-archive',
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id);

        $path = $response->json('data.chemin_apk');
        Storage::disk('local')->assertExists($path);

        $terminal = $this->terminal($organization, 'APK target');
        DeviceCredential::create([
            'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', 'apk-target-token'),
        ]);
        Lic::create([
            'organization_id' => $organization->id,
            'cle' => 'MDM-APK-TARGET',
            'term_id' => $terminal->id,
            'statut' => 'Active',
            'exp_le' => now()->addYear(),
        ]);

        $update = $this->post("/api/apps/{$response->json('data.id')}/update", [
            'ver' => '2.0.0',
            'chemin_apk' => UploadedFile::fake()->create(
                'metier-2.apk',
                120,
                'application/vnd.android.package-archive',
            ),
        ]);
        $update->assertOk()->assertJsonPath('data.ver', '2.0.0');
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($update->json('data.chemin_apk'));

        $deployment = $this->postJson("/api/apps/{$response->json('data.id')}/deploy", [
            'terminal_ids' => [$terminal->id, 999999],
        ]);
        $deployment->assertStatus(207)
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonCount(1, 'data.rejected')
            ->assertJsonPath('data.accepted.0.terminal_id', $terminal->id);

        $command = DeviceCommand::query()
            ->where('terminal_id', $terminal->id)
            ->where('type', 'install_app')
            ->firstOrFail();
        $this->assertSame('com.example.metier', $command->payload['packageName']);

        $this->get($command->payload['url'])
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive');
    }

    public function test_super_admin_must_select_an_organization_when_creating_a_licence(): void
    {
        $organization = $this->organization('licence-owner');
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

        $this->postJson('/api/lics', [])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->postJson('/api/lics', ['organization_id' => $organization->id])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id);
    }

    public function test_super_admin_creates_a_client_with_an_isolated_organization(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

        $this->postJson('/api/users', [
            'name' => 'Admin Client',
            'organization_name' => 'Entreprise Client',
            'email' => 'client@example.test',
            'password' => 'StrongPassword123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.organization.name', 'Entreprise Client')
            ->assertJsonPath('data.role', 'admin');

        $organizationId = User::query()
            ->where('email', 'client@example.test')
            ->value('organization_id');

        $this->assertNotNull($organizationId);
        $this->assertDatabaseHas('organizations', [
            'id' => $organizationId,
            'name' => 'Entreprise Client',
        ]);
    }

    public function test_admin_login_returns_its_organization(): void
    {
        $organization = $this->organization('login-org');
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'admin',
            'password' => 'LoginPassword123',
        ]);

        $this->postJson('/api/auth/in', [
            'email' => $user->email,
            'password' => 'LoginPassword123',
        ])
            ->assertOk()
            ->assertJsonPath('usr.organization_id', $organization->id)
            ->assertJsonPath('usr.organization.name', $organization->name);
    }

    public function test_admin_can_revoke_a_device_identity_from_its_organization(): void
    {
        $organization = $this->organization('revoke-org');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $organization->id,
        ]);
        $terminal = $this->terminal($organization, 'To revoke');
        $plainToken = 'mdm_device_test_token';
        $credential = DeviceCredential::create([
            'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/terminals/{$terminal->id}/revoke-credential")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($credential->fresh()->revoked_at);
        $this->assertSame('revoked', $terminal->fresh()->enrollment_status);

        $this->withToken($plainToken)
            ->postJson('/api/v1/device/heartbeat', [])
            ->assertUnauthorized();
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'active' => true,
        ]);
    }

    private function terminal(Organization $organization, string $model): Terminal
    {
        return Terminal::create([
            'organization_id' => $organization->id,
            'modele' => $model,
            'statut' => 'Hors ligne',
            'enrollment_status' => 'enrolled',
        ]);
    }
}
