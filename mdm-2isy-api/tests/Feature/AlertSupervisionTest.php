<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DeviceCommand;
use App\Models\Lic;
use App\Models\Organization;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertSupervisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervision_creates_deduplicates_and_resolves_health_alerts(): void
    {
        $organization = $this->organization('supervision');
        $terminal = Terminal::create([
            'organization_id' => $organization->id,
            'modele' => 'Rock 1 Pro',
            'enrollment_status' => 'enrolled',
            'last_seen_at' => now()->subHours(2),
            'batterie' => 12,
            'storage_total_mb' => 10_000,
            'storage_free_mb' => 400,
        ]);
        Lic::create([
            'organization_id' => $organization->id,
            'term_id' => $terminal->id,
            'cle' => 'MDM-ALERT-SOON',
            'statut' => 'Active',
            'exp_le' => now()->addDays(10),
        ]);

        Artisan::call('mdm:check-alerts');

        $this->assertDatabaseCount('alerts', 4);
        $this->assertEqualsCanonicalizing(
            ['offline', 'battery', 'storage', 'licence_expiring'],
            Alert::query()->pluck('type')->all(),
        );
        $this->assertSame($organization->id, Alert::query()->firstOrFail()->organization_id);

        Artisan::call('mdm:check-alerts');
        $this->assertDatabaseCount('alerts', 4);

        $terminal->update([
            'last_seen_at' => now(),
            'batterie' => 80,
            'storage_free_mb' => 5_000,
        ]);
        $terminal->lic->update(['exp_le' => now()->addYear()]);
        Artisan::call('mdm:check-alerts');

        $this->assertSame(0, Alert::query()->whereNull('resolved_at')->count());
    }

    public function test_failed_command_creates_one_tenant_scoped_alert(): void
    {
        $organization = $this->organization('command-alert');
        $terminal = Terminal::create([
            'organization_id' => $organization->id,
            'modele' => 'Rock 1 Pro',
            'enrollment_status' => 'enrolled',
            'last_seen_at' => now(),
        ]);
        $actor = User::factory()->create([
            'role' => 'admin',
            'organization_id' => $organization->id,
        ]);
        $command = DeviceCommand::create([
            'organization_id' => $organization->id,
            'terminal_id' => $terminal->id,
            'created_by' => $actor->id,
            'type' => DeviceCommand::TYPE_INSTALL_APP,
            'status' => DeviceCommand::STATUS_FAILED,
            'idempotency_key' => hash('sha256', 'failed-command'),
            'queued_at' => now()->subMinute(),
            'completed_at' => now(),
            'expires_at' => now()->addMinute(),
            'error_code' => 'INSTALL_FAILED',
            'error_message' => 'Signature APK incompatible',
        ]);

        Artisan::call('mdm:check-alerts');
        Artisan::call('mdm:check-alerts');

        $this->assertDatabaseHas('alerts', [
            'organization_id' => $organization->id,
            'terminal_id' => $terminal->id,
            'type' => 'command_failure',
            'source_key' => 'command:'.$command->public_id,
        ]);
        $this->assertSame(1, Alert::query()->where('type', 'command_failure')->count());

        Sanctum::actingAs($actor);
        $this->getJson('/api/alerts?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.severity', 'warning');
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'active' => true,
        ]);
    }
}
