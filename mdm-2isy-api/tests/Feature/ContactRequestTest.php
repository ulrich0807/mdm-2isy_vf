<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_send_a_contact_request(): void
    {
        $response = $this->postJson('/api/contact-requests', [
            'name' => 'Jean Kouassi',
            'company' => 'Entreprise Démo',
            'email' => 'jean@example.test',
            'contact' => '+225 01 02 03 04 05',
            'issue' => 'Je souhaite obtenir une démonstration de la plateforme MDM.',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('contact_requests', [
            'email' => 'jean@example.test',
            'status' => 'new',
        ]);
    }

    public function test_contact_request_fields_are_validated(): void
    {
        $this->postJson('/api/contact-requests', [
            'name' => '',
            'company' => '',
            'email' => 'invalid',
            'contact' => '',
            'issue' => 'court',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'company', 'email', 'contact', 'issue']);
    }

    public function test_only_a_super_admin_can_manage_contact_requests(): void
    {
        $contact = ContactRequest::create([
            'name' => 'Awa Traoré',
            'company' => 'AT Services',
            'email' => 'awa@example.test',
            'contact' => '+225 07 00 00 00 00',
            'issue' => 'Nous rencontrons un problème pendant la création du compte.',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/contact-requests')->assertForbidden();
        $this->patchJson("/api/contact-requests/{$contact->id}", ['status' => 'read'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->getJson('/api/contact-requests')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'awa@example.test');

        $this->patchJson("/api/contact-requests/{$contact->id}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertNotNull($contact->fresh()->resolved_at);
    }
}
