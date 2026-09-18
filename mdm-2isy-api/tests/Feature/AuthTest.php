<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receives_identity_and_role(): void
    {
        $user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@example.test',
            'password' => Hash::make('a-secure-password'),
            'role' => 'super_admin',
        ]);

        $response = $this->postJson('/api/auth/in', [
            'email' => $user->email,
            'password' => 'a-secure-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('usr.id', $user->id)
            ->assertJsonPath('usr.name', $user->name)
            ->assertJsonPath('usr.email', $user->email)
            ->assertJsonPath('usr.role', 'super_admin')
            ->assertJsonStructure(['tok']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_credentials_without_creating_a_token(): void
    {
        User::create([
            'name' => 'Admin Test',
            'email' => 'admin@example.test',
            'password' => Hash::make('a-secure-password'),
            'role' => 'admin',
        ]);

        $this->postJson('/api/auth/in', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Identifiants incorrects.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_validates_its_payload(): void
    {
        $this->postJson('/api/auth/in', [
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@example.test',
            'password' => Hash::make('a-secure-password'),
            'role' => 'admin',
        ]);
        $currentToken = $user->createToken('current');
        $otherToken = $user->createToken('other');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/auth/out')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Déconnexion réussie.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/out')->assertUnauthorized();
    }
}
