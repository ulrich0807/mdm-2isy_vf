<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\Organization;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetBusinessDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_explicit_confirmation(): void
    {
        User::factory()->create(['role' => 'super_admin']);

        $this->artisan('mdm:reset-business-data', ['--force' => true])
            ->assertFailed();
    }

    public function test_command_preserves_only_super_admin_accounts(): void
    {
        $organization = Organization::create([
            'name' => 'Données de test',
            'slug' => 'donnees-test',
            'active' => true,
        ]);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'organization_id' => $organization->id,
        ]);
        User::factory()->create(['role' => 'admin', 'organization_id' => $organization->id]);
        Terminal::create([
            'organization_id' => $organization->id,
            'imei' => 'RESET-TEST-IMEI',
            'enrollment_status' => 'enrolled',
        ]);
        ContactRequest::create([
            'name' => 'Client Test',
            'company' => 'Test SARL',
            'email' => 'client@example.test',
            'contact' => '+2250000000000',
            'issue' => 'Ceci est une demande à supprimer pendant le test.',
        ]);

        $this->artisan('mdm:reset-business-data', [
            '--force' => true,
            '--confirmation' => 'RESET-MDM-DATA',
        ])->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'role' => 'super_admin']);
        $this->assertNull($superAdmin->fresh()->organization_id);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('terminals', 0);
        $this->assertDatabaseCount('contact_requests', 0);
    }

    public function test_command_refuses_to_run_without_a_super_admin(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->artisan('mdm:reset-business-data', [
            '--force' => true,
            '--confirmation' => 'RESET-MDM-DATA',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
