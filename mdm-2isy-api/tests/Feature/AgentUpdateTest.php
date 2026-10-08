<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use App\Models\DeviceCredential;
use App\Models\Lic;
use App\Models\Organization;
use App\Models\Terminal;
use App\Models\User;
use App\Services\AgentReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_inspect_and_start_agent_updates(): void
    {
        $organization = $this->organization('agent-role');
        $admin = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $organization->id,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/agent-updates')->assertForbidden();
        $this->postJson('/api/agent-updates', [
            'mode' => 'pilot',
            'terminal_ids' => [1],
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'organization_id' => null,
        ]));

        $this->getJson('/api/agent-updates')
            ->assertOk()
            ->assertJsonPath('data.release.package_name', AgentReleaseService::PACKAGE_NAME)
            ->assertJsonPath('data.rollout.pilot_max_terminals', 1)
            ->assertJsonPath('data.rollout.batch_max_terminals', 100);
    }

    public function test_pilot_queues_a_retrocompatible_content_addressed_update(): void
    {
        $organization = $this->organization('agent-pilot');
        [$terminal, $deviceToken] = $this->terminal($organization, '0.1.9');
        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'organization_id' => null,
        ]));
        $release = app(AgentReleaseService::class)->metadata();

        $created = $this->postJson('/api/agent-updates', [
            'organization_id' => $organization->id,
            'mode' => 'pilot',
            'terminal_ids' => [$terminal->id],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode', 'pilot')
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonCount(0, 'data.rejected');

        $command = DeviceCommand::query()->sole();
        $this->assertSame(DeviceCommand::TYPE_INSTALL_APP, $command->type);
        $this->assertSame(AgentReleaseService::PACKAGE_NAME, $command->payload['packageName']);
        $this->assertSame(300, $command->payload['timeout_seconds']);
        $this->assertSame($release['marker'], $command->payload['message']);
        $this->assertStringContainsString(
            "/download/mdm-agent/releases/{$release['sha256']}.apk",
            $command->payload['url'],
        );
        $this->assertTrue($command->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame($command->public_id, $created->json('data.accepted.0.command_id'));

        // L'agent 0.1.9 reçoit toujours le type qu'il connaît déjà. Les
        // informations supplémentaires utilisent des champs existants.
        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands')
            ->assertOk()
            ->assertJsonPath('data.0.type', DeviceCommand::TYPE_INSTALL_APP)
            ->assertJsonPath('data.0.payload.packageName', AgentReleaseService::PACKAGE_NAME)
            ->assertJsonPath('data.0.payload.timeout_seconds', 300)
            ->assertJsonPath('data.0.payload.message', $release['marker']);
    }

    public function test_rollout_rejects_duplicate_current_and_cross_tenant_targets(): void
    {
        $organization = $this->organization('agent-batch');
        $foreignOrganization = $this->organization('agent-foreign');
        $targetVersion = app(AgentReleaseService::class)->metadata()['version_name'];
        [$outdated] = $this->terminal($organization, '0.1.9');
        [$current] = $this->terminal($organization, $targetVersion);
        [$foreign] = $this->terminal($foreignOrganization, '0.1.9');
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'organization_id' => null]);
        Sanctum::actingAs($superAdmin);

        $first = $this->postJson('/api/agent-updates', [
            'organization_id' => $organization->id,
            'mode' => 'batch',
            'terminal_ids' => [$outdated->id, $current->id, $foreign->id],
        ])
            ->assertStatus(207)
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonCount(2, 'data.rejected')
            ->assertJsonFragment(['terminal_id' => $current->id, 'reason' => 'already_current'])
            ->assertJsonFragment(['terminal_id' => $foreign->id, 'reason' => 'terminal_not_found']);

        $this->assertTrue($first->json('success'));

        $this->postJson('/api/agent-updates', [
            'organization_id' => $organization->id,
            'mode' => 'pilot',
            'terminal_ids' => [$outdated->id],
        ])
            ->assertStatus(207)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.rejected.0.reason', 'update_already_pending');

        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_pilot_accepts_only_one_terminal_and_generic_agent_deployment_is_blocked(): void
    {
        $organization = $this->organization('agent-guards');
        [$first] = $this->terminal($organization, '0.1.8');
        [$second] = $this->terminal($organization, '0.1.9');
        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'organization_id' => null,
        ]));

        $this->postJson('/api/agent-updates', [
            'organization_id' => $organization->id,
            'mode' => 'pilot',
            'terminal_ids' => [$first->id, $second->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('terminal_ids');

        $this->withHeader('Idempotency-Key', 'reserved-agent-package-0001')
            ->postJson("/api/terminals/{$first->public_id}/commands", [
                'type' => 'install_app',
                'payload' => [
                    'url' => 'https://api.example.test/download/agent.apk',
                    'packageName' => AgentReleaseService::PACKAGE_NAME,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.packageName');

        $this->postJson('/api/apps', [
            'organization_id' => $organization->id,
            'nom' => 'Agent interdit',
            'pkg' => AgentReleaseService::PACKAGE_NAME,
            'type' => 'blanche',
        ])->assertUnprocessable()->assertJsonValidationErrors('pkg');

        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_immutable_release_download_has_long_lived_cache_and_checksum_headers(): void
    {
        $release = app(AgentReleaseService::class)->metadata();

        $response = $this->get("/download/mdm-agent/releases/{$release['sha256']}.apk");
        $response
            ->assertOk()
            ->assertHeader('X-Checksum-Sha256', $release['sha256'])
            ->assertHeader('etag', '"'.$release['sha256'].'"');

        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);

        $this->get('/download/mdm-agent/releases/'.str_repeat('0', 64).'.apk')
            ->assertNotFound();
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'active' => true,
        ]);
    }

    /** @return array{Terminal, string} */
    private function terminal(Organization $organization, string $agentVersion): array
    {
        $terminal = Terminal::create([
            'organization_id' => $organization->id,
            'modele' => 'Blackview Rock 1 Pro',
            'agent_version' => $agentVersion,
            'enrollment_status' => 'enrolled',
            'management_state' => 'active',
            'statut' => 'En ligne',
        ]);
        $token = 'agent-update-device-'.$terminal->id;
        DeviceCredential::create([
            'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', $token),
        ]);
        Lic::create([
            'organization_id' => $organization->id,
            'term_id' => $terminal->id,
            'cle' => 'MDM-AGENT-UPDATE-'.str_pad((string) $terminal->id, 6, '0', STR_PAD_LEFT),
            'statut' => 'Active',
            'exp_le' => now()->addYear(),
        ]);

        return [$terminal, $token];
    }
}
