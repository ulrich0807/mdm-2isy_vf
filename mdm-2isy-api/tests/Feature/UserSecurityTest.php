<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_creation_requires_a_strong_password(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $token = $superAdmin->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/users', [
            'name' => 'Client Test',
            'email' => 'client@example.test',
            'password' => 'weak',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'client@example.test']);
    }

    public function test_password_change_revokes_all_active_tokens(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123',
            'role' => 'admin',
        ]);
        $currentToken = $user->createToken('current')->plainTextToken;
        $user->createToken('other');

        $this->withToken($currentToken)->putJson('/api/users/pwd', [
            'old_pwd' => 'OldPassword123',
            'new_pwd' => 'NewPassword456',
            'new_pwd_confirmation' => 'NewPassword456',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
