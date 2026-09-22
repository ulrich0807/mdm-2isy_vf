<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use App\Models\DeviceCredential;
use App\Models\Lic;
use App\Models\Organization;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceCommandApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_queue_and_read_commands_only_in_its_tenant(): void
    {
        $ownOrganization = $this->organization('commands-own');
        $foreignOrganization = $this->organization('commands-foreign');
        [$ownTerminal] = $this->terminalWithCredential($ownOrganization, 'own-device-token');
        [$foreignTerminal] = $this->terminalWithCredential($foreignOrganization, 'foreign-device-token');
        $admin = $this->user($ownOrganization);

        Sanctum::actingAs($admin);

        $created = $this->withHeader('Idempotency-Key', 'admin-command-0001')
            ->postJson("/api/terminals/{$ownTerminal->public_id}/commands", [
                'type' => 'locate',
                'payload' => [
                    'timeout_seconds' => 30,
                    'high_accuracy' => true,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'locate')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.created_by');

        $commandPublicId = $created->json('data.public_id');

        $this->getJson("/api/terminals/{$ownTerminal->public_id}/commands")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $commandPublicId);

        $this->withHeader('Idempotency-Key', 'admin-command-0002')
            ->postJson("/api/terminals/{$foreignTerminal->public_id}/commands", [
                'type' => 'lock',
            ])
            ->assertNotFound();

        $viewer = $this->user($ownOrganization, 'viewer');
        Sanctum::actingAs($viewer);

        $this->getJson("/api/terminals/{$ownTerminal->public_id}/commands")
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'organization_id' => null,
        ]));

        $this->withHeader('Idempotency-Key', 'super-admin-command-0001')
            ->postJson("/api/terminals/{$foreignTerminal->public_id}/commands", [
                'type' => 'lock',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'lock');
    }

    public function test_idempotency_is_hashed_case_sensitive_and_scoped_to_the_actor(): void
    {
        $organization = $this->organization('commands-idempotency');
        [$terminal] = $this->terminalWithCredential($organization, 'idempotent-device-token');
        $admin = $this->user($organization);
        Sanctum::actingAs($admin);
        $url = "/api/terminals/{$terminal->public_id}/commands";

        $first = $this->withHeader('Idempotency-Key', 'idempotency-key-0001')
            ->postJson($url, ['type' => 'locate'])
            ->assertCreated();

        $second = $this->withHeader('Idempotency-Key', 'idempotency-key-0001')
            ->postJson($url, ['type' => 'locate'])
            ->assertOk();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertDatabaseCount('device_commands', 1);

        $storedHash = DeviceCommand::query()->sole()->getRawOriginal('idempotency_key');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $storedHash);
        $this->assertNotSame('idempotency-key-0001', $storedHash);
        $this->assertSame(
            hash('sha256', "device-command:v1\0{$admin->id}\0idempotency-key-0001"),
            $storedHash,
        );

        $this->withHeader('Idempotency-Key', 'idempotency-key-0001')
            ->postJson($url, ['type' => 'lock'])
            ->assertConflict()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('device_commands', 1);

        $this->withHeader('Idempotency-Key', 'Idempotency-key-0001')
            ->postJson($url, ['type' => 'locate'])
            ->assertCreated();

        Sanctum::actingAs($this->user($organization));
        $this->withHeader('Idempotency-Key', 'idempotency-key-0001')
            ->postJson($url, ['type' => 'locate'])
            ->assertCreated();

        $this->assertDatabaseCount('device_commands', 3);
    }

    public function test_wipe_requires_exact_confirmation_and_current_password_without_persisting_them(): void
    {
        $organization = $this->organization('commands-wipe');
        [$terminal] = $this->terminalWithCredential($organization, 'wipe-device-token');
        $admin = $this->user($organization, 'admin', 'CorrectPassword123!');
        Sanctum::actingAs($admin);
        $url = "/api/terminals/{$terminal->public_id}/commands";

        $this->withHeader('Idempotency-Key', 'wipe-command-wrong-confirmation')
            ->postJson($url, [
                'type' => 'wipe',
                'confirmation' => strtoupper($terminal->public_id),
                'current_password' => 'CorrectPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->withHeader('Idempotency-Key', 'wipe-command-wrong-password')
            ->postJson($url, [
                'type' => 'wipe',
                'confirmation' => $terminal->public_id,
                'current_password' => 'NotThePassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->withHeader('Idempotency-Key', 'wipe-command-correct-0001')
            ->postJson($url, [
                'type' => 'wipe',
                'confirmation' => $terminal->public_id,
                'current_password' => 'CorrectPassword123!',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'wipe');

        $stored = DeviceCommand::query()->sole();
        $serializedAttributes = json_encode($stored->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertSame([], $stored->payload);
        $this->assertStringNotContainsString('CorrectPassword123!', $serializedAttributes);
        $this->assertStringNotContainsString('NotThePassword123!', $serializedAttributes);
    }

    public function test_device_poll_ack_and_result_are_scoped_and_follow_the_state_machine(): void
    {
        $organization = $this->organization('commands-device');
        [$terminal, $deviceToken] = $this->terminalWithCredential($organization, 'command-device-token-a');
        [$otherTerminal, $otherDeviceToken] = $this->terminalWithCredential($organization, 'command-device-token-b');
        $admin = $this->user($organization);
        Sanctum::actingAs($admin);

        $created = $this->withHeader('Idempotency-Key', 'device-flow-command-0001')
            ->postJson("/api/terminals/{$terminal->public_id}/commands", [
                'type' => 'locate',
            ])
            ->assertCreated();
        $commandPublicId = $created->json('data.public_id');

        $this->withToken($otherDeviceToken)
            ->getJson('/api/v1/device/commands')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($otherDeviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/ack")
            ->assertNotFound();

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $commandPublicId)
            ->assertJsonMissingPath('data.0.status')
            ->assertJsonMissingPath('data.0.organization_id')
            ->assertJsonMissingPath('data.0.created_by');

        $this->assertDatabaseHas('device_commands', [
            'public_id' => $commandPublicId,
            'terminal_id' => $terminal->id,
            'status' => 'sent',
        ]);

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=5')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('device_commands', [
            'public_id' => $commandPublicId,
            'delivery_attempts' => 1,
        ]);

        DeviceCommand::query()
            ->where('public_id', $commandPublicId)
            ->update(['delivery_attempts' => 19]);

        $this->travel(16)->seconds();

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $commandPublicId);

        $this->assertDatabaseHas('device_commands', [
            'public_id' => $commandPublicId,
            'delivery_attempts' => 20,
        ]);

        $this->travel(16)->seconds();

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=5')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/ack")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'succeeded',
                'result' => ['message' => 'Position unavailable'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['result.lat', 'result.lng']);

        $result = [
            'status' => 'succeeded',
            'result' => [
                'lat' => 5.348,
                'lng' => -4.027,
                'accuracy_m' => 12.5,
            ],
        ];

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", $result)
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertSame('5.34800000', $terminal->fresh()->lat);
        $this->assertSame('-4.02700000', $terminal->fresh()->lng);

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", $result)
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'failed',
                'error_code' => 'CONTRADICTORY_RESULT',
            ])
            ->assertConflict();

        Sanctum::actingAs($admin);
        $this->getJson("/api/terminals/{$terminal->public_id}/commands")
            ->assertOk()
            ->assertJsonPath('data.0.events.0.to_status', 'queued')
            ->assertJsonPath('data.0.events.1.to_status', 'sent')
            ->assertJsonPath('data.0.events.2.to_status', 'acknowledged')
            ->assertJsonPath('data.0.events.3.to_status', 'succeeded')
            ->assertJsonCount(4, 'data.0.events')
            ->assertJsonMissingPath('data.0.events.0.actor_id');

        $this->assertSame('active', $otherTerminal->fresh()->management_state);
    }

    public function test_poll_prioritizes_new_wipe_then_lock_before_redelivery(): void
    {
        $organization = $this->organization('commands-priority');
        [$terminal, $deviceToken] = $this->terminalWithCredential(
            $organization,
            'priority-device-token',
        );
        $admin = $this->user($organization, 'admin', 'PriorityPassword123!');
        Sanctum::actingAs($admin);

        $locatePublicId = $this->withHeader('Idempotency-Key', 'priority-locate-0001')
            ->postJson("/api/terminals/{$terminal->public_id}/commands", [
                'type' => 'locate',
            ])
            ->json('data.public_id');

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $locatePublicId);

        $this->travel(16)->seconds();
        Sanctum::actingAs($admin);

        $lockPublicId = $this->withHeader('Idempotency-Key', 'priority-lock-0001')
            ->postJson("/api/terminals/{$terminal->public_id}/commands", [
                'type' => 'lock',
            ])
            ->json('data.public_id');
        $wipePublicId = $this->withHeader('Idempotency-Key', 'priority-wipe-0001')
            ->postJson("/api/terminals/{$terminal->public_id}/commands", [
                'type' => 'wipe',
                'confirmation' => $terminal->public_id,
                'current_password' => 'PriorityPassword123!',
            ])
            ->json('data.public_id');

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $wipePublicId);

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $lockPublicId);

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $locatePublicId);
    }

    public function test_device_result_shape_and_error_size_are_bounded(): void
    {
        $organization = $this->organization('commands-bounds');
        [$terminal, $deviceToken] = $this->terminalWithCredential($organization, 'bounded-device-token');
        Sanctum::actingAs($this->user($organization));

        $commandPublicId = $this->withHeader('Idempotency-Key', 'bounded-command-0001')
            ->postJson("/api/terminals/{$terminal->public_id}/commands", ['type' => 'locate'])
            ->json('data.public_id');

        $this->withToken($deviceToken)->getJson('/api/v1/device/commands')->assertOk();
        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/ack")
            ->assertOk();

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'succeeded',
                'result' => ['arbitrary_blob' => str_repeat('x', 10000)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'failed',
                'error_code' => 'TOO_VERBOSE',
                'error_message' => str_repeat('x', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('error_message');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'succeeded',
                'result' => ['lat' => 91, 'lng' => 181],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['result.lat', 'result.lng']);

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'failed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('error_code');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'failed',
                'error_code' => 'DEVICE_POLICY_REJECTED',
                'error_message' => 'The device rejected this command.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('device_commands', [
            'public_id' => $commandPublicId,
            'status' => 'failed',
            'error_code' => 'DEVICE_POLICY_REJECTED',
        ]);
    }

    public function test_successful_application_results_do_not_require_a_wipe_proof(): void
    {
        $organization = $this->organization('commands-app-results');
        [$terminal, $deviceToken] = $this->terminalWithCredential(
            $organization,
            'application-result-device-token',
        );
        Sanctum::actingAs($this->user($organization));
        $url = "/api/terminals/{$terminal->public_id}/commands";

        $installPublicId = $this->withHeader('Idempotency-Key', 'install-result-command-0001')
            ->postJson($url, [
                'type' => 'install_app',
                'payload' => [
                    'url' => 'https://mdm.example.test/apps/business.apk',
                    'packageName' => 'com.example.business',
                ],
            ])
            ->assertCreated()
            ->json('data.public_id');
        $uninstallPublicId = $this->withHeader('Idempotency-Key', 'uninstall-result-command-0001')
            ->postJson($url, [
                'type' => 'uninstall_app',
                'payload' => ['packageName' => 'com.example.legacy'],
            ])
            ->assertCreated()
            ->json('data.public_id');

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ([
            $installPublicId => 'Installation de l’application lancée avec succès.',
            $uninstallPublicId => 'Désinstallation de l’application lancée avec succès.',
        ] as $publicId => $message) {
            $this->withToken($deviceToken)
                ->postJson("/api/v1/device/commands/{$publicId}/ack")
                ->assertOk();
            $this->withToken($deviceToken)
                ->postJson("/api/v1/device/commands/{$publicId}/result", [
                    'status' => 'succeeded',
                    'result' => [
                        'message' => $message,
                        'executed_at' => now()->toIso8601String(),
                    ],
                ])
                ->assertOk()
                ->assertJsonPath('data.status', 'succeeded');
        }

        $this->assertDatabaseCount('device_commands', 2);
        $this->assertSame(
            2,
            DeviceCommand::query()->where('status', DeviceCommand::STATUS_SUCCEEDED)->count(),
        );
    }

    public function test_successful_lock_and_wipe_apply_terminal_security_effects(): void
    {
        $organization = $this->organization('commands-effects');
        [$lockTerminal, $lockToken] = $this->terminalWithCredential($organization, 'lock-effect-token');
        [$wipeTerminal, $wipeToken, $wipeCredential] = $this->terminalWithCredential($organization, 'wipe-effect-token');
        $admin = $this->user($organization, 'admin', 'EffectsPassword123!');
        Sanctum::actingAs($admin);

        $lockCommand = $this->withHeader('Idempotency-Key', 'lock-effect-command-0001')
            ->postJson("/api/terminals/{$lockTerminal->public_id}/commands", ['type' => 'lock'])
            ->json('data.public_id');

        $this->withToken($lockToken)->getJson('/api/v1/device/commands')->assertOk();
        $this->withToken($lockToken)
            ->postJson("/api/v1/device/commands/{$lockCommand}/ack")
            ->assertOk();
        $this->withToken($lockToken)
            ->postJson("/api/v1/device/commands/{$lockCommand}/result", [
                'status' => 'succeeded',
                'result' => ['locked' => false],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result.locked');
        $this->withToken($lockToken)
            ->postJson("/api/v1/device/commands/{$lockCommand}/result", [
                'status' => 'succeeded',
                'result' => ['locked' => true],
            ])
            ->assertOk();
        $this->assertSame('locked', $lockTerminal->fresh()->management_state);

        Sanctum::actingAs($admin);
        $wipeCommand = $this->withHeader('Idempotency-Key', 'wipe-effect-command-0001')
            ->postJson("/api/terminals/{$wipeTerminal->public_id}/commands", [
                'type' => 'wipe',
                'confirmation' => $wipeTerminal->public_id,
                'current_password' => 'EffectsPassword123!',
            ])
            ->json('data.public_id');

        $this->withToken($wipeToken)->getJson('/api/v1/device/commands')->assertOk();
        $this->withToken($wipeToken)
            ->postJson("/api/v1/device/commands/{$wipeCommand}/ack")
            ->assertOk();
        $this->withToken($wipeToken)
            ->postJson("/api/v1/device/commands/{$wipeCommand}/result", [
                'status' => 'succeeded',
                'result' => ['wipe_started' => false],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result.wipe_started');
        $this->withToken($wipeToken)
            ->postJson("/api/v1/device/commands/{$wipeCommand}/result", [
                'status' => 'succeeded',
                'result' => ['wipe_started' => true],
            ])
            ->assertOk();

        $this->assertSame('wiped', $wipeTerminal->fresh()->management_state);
        $this->assertNotNull($wipeCredential->fresh()->revoked_at);
        $this->withToken($wipeToken)->getJson('/api/v1/device/commands')->assertUnauthorized();
    }

    public function test_successful_wipe_expires_other_pending_commands_with_audit(): void
    {
        $organization = $this->organization('commands-wipe-cancellation');
        [$terminal, $deviceToken, $credential] = $this->terminalWithCredential(
            $organization,
            'wipe-cancellation-token',
        );
        $admin = $this->user($organization, 'admin', 'CancellationPassword123!');
        Sanctum::actingAs($admin);
        $url = "/api/terminals/{$terminal->public_id}/commands";

        $lockPublicId = $this->withHeader('Idempotency-Key', 'cancel-lock-0001')
            ->postJson($url, ['type' => 'lock'])
            ->json('data.public_id');
        $locatePublicId = $this->withHeader('Idempotency-Key', 'cancel-locate-0001')
            ->postJson($url, ['type' => 'locate'])
            ->json('data.public_id');
        $wipePublicId = $this->withHeader('Idempotency-Key', 'cancel-wipe-0001')
            ->postJson($url, [
                'type' => 'wipe',
                'confirmation' => $terminal->public_id,
                'current_password' => 'CancellationPassword123!',
            ])
            ->json('data.public_id');

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands?limit=10')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.public_id', $wipePublicId);

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$wipePublicId}/ack")
            ->assertOk();
        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$wipePublicId}/result", [
                'status' => 'succeeded',
                'result' => ['wipe_started' => true],
            ])
            ->assertOk();

        $this->assertSame('wiped', $terminal->fresh()->management_state);
        $this->assertNotNull($credential->fresh()->revoked_at);
        $this->assertDatabaseHas('device_commands', [
            'public_id' => $wipePublicId,
            'status' => 'succeeded',
        ]);

        foreach ([$lockPublicId, $locatePublicId] as $expiredPublicId) {
            $expired = DeviceCommand::query()
                ->where('public_id', $expiredPublicId)
                ->firstOrFail();
            $event = $expired->events()->get()->last();

            $this->assertSame('expired', $expired->status);
            $this->assertSame('expired', $event?->to_status);
            $this->assertSame('device_wiped', $event?->metadata['reason'] ?? null);
            $this->assertSame($wipePublicId, $event?->metadata['superseded_by'] ?? null);
        }
    }

    public function test_expired_commands_are_lazily_removed_from_delivery(): void
    {
        $organization = $this->organization('commands-expiration');
        [$terminal, $deviceToken] = $this->terminalWithCredential($organization, 'expiration-device-token');
        Sanctum::actingAs($this->user($organization));

        $commandPublicId = $this->withHeader('Idempotency-Key', 'expiration-command-0001')
            ->postJson("/api/terminals/{$terminal->public_id}/commands", ['type' => 'lock'])
            ->json('data.public_id');

        DeviceCommand::query()
            ->where('public_id', $commandPublicId)
            ->update(['expires_at' => now()->subMinute()]);

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('device_commands', [
            'public_id' => $commandPublicId,
            'status' => 'expired',
        ]);

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/ack")
            ->assertConflict()
            ->assertJsonPath('reason', 'command_expired');

        $this->withToken($deviceToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/result", [
                'status' => 'failed',
                'error_code' => 'COMMAND_EXPIRED',
            ])
            ->assertConflict()
            ->assertJsonPath('reason', 'command_expired');
    }

    public function test_command_requires_an_enrolled_device_with_an_active_credential(): void
    {
        $organization = $this->organization('commands-identity');
        $pendingTerminal = Terminal::create([
            'organization_id' => $organization->id,
            'modele' => 'Pending device',
            'statut' => 'Hors ligne',
            'enrollment_status' => 'pending',
        ]);
        $this->activateLicenseFor($pendingTerminal);
        [$revokedTerminal, , $revokedCredential] = $this->terminalWithCredential(
            $organization,
            'revoked-command-token',
        );
        $revokedCredential->update(['revoked_at' => now()]);
        Sanctum::actingAs($this->user($organization));

        $this->withHeader('Idempotency-Key', 'pending-device-command-0001')
            ->postJson("/api/terminals/{$pendingTerminal->public_id}/commands", [
                'type' => 'lock',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'device_not_enrolled');

        $this->withHeader('Idempotency-Key', 'revoked-device-command-0001')
            ->postJson("/api/terminals/{$revokedTerminal->public_id}/commands", [
                'type' => 'locate',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'device_not_enrolled');

        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_device_authentication_rejects_an_inactive_organization(): void
    {
        $organization = $this->organization('commands-inactive-org');
        [, $deviceToken, $credential] = $this->terminalWithCredential(
            $organization,
            'inactive-organization-device-token',
        );
        $organization->update(['active' => false]);

        $this->withToken($deviceToken)
            ->getJson('/api/v1/device/commands')
            ->assertUnauthorized();

        $this->withToken($deviceToken)
            ->postJson('/api/v1/device/heartbeat')
            ->assertUnauthorized();

        $this->assertNull($credential->fresh()->last_used_at);
    }

    public function test_device_rate_limits_are_keyed_by_identity_and_separated_by_action(): void
    {
        $organization = $this->organization('commands-rate-limits');
        [$firstTerminal, $firstToken] = $this->terminalWithCredential($organization, 'rate-limit-device-token-a');
        [, $secondToken] = $this->terminalWithCredential($organization, 'rate-limit-device-token-b');
        Sanctum::actingAs($this->user($organization));
        $commandPublicId = $this->withHeader('Idempotency-Key', 'rate-limit-command-0001')
            ->postJson("/api/terminals/{$firstTerminal->public_id}/commands", ['type' => 'lock'])
            ->assertCreated()
            ->json('data.public_id');

        $this->withToken($firstToken)
            ->getJson('/api/v1/device/commands')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');

        $this->withToken($secondToken)
            ->getJson('/api/v1/device/commands')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');

        $this->withToken($firstToken)
            ->postJson("/api/v1/device/commands/{$commandPublicId}/ack")
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '120')
            ->assertHeader('X-RateLimit-Remaining', '119');
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'active' => true,
        ]);
    }

    private function user(
        Organization $organization,
        string $role = 'admin',
        string $password = 'password',
    ): User {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'role' => $role,
            'password' => $password,
        ]);
    }

    /**
     * @return array{Terminal, string, DeviceCredential}
     */
    private function terminalWithCredential(Organization $organization, string $plainToken): array
    {
        $terminal = Terminal::create([
            'organization_id' => $organization->id,
            'modele' => 'Command test device',
            'statut' => 'En ligne',
            'enrollment_status' => 'enrolled',
            'management_state' => 'active',
        ]);
        $credential = DeviceCredential::create([
            'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $this->activateLicenseFor($terminal);

        return [$terminal, $plainToken, $credential];
    }

    private function activateLicenseFor(Terminal $terminal): void
    {
        Lic::query()->create([
            'cle' => 'MDM-TEST-'.str_pad((string) $terminal->id, 6, '0', STR_PAD_LEFT),
            'term_id' => $terminal->id,
            'statut' => 'Active',
            'exp_le' => now()->addYear(),
        ]);
    }
}
