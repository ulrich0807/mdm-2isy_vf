<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsSecurityTest extends TestCase
{
    public function test_configured_frontend_origin_is_allowed(): void
    {
        config()->set('cors.allowed_origins', ['https://mdm-2isy.com']);

        $this->withHeaders([
            'Origin' => 'https://mdm-2isy.com',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/ping')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'https://mdm-2isy.com');
    }

    public function test_unknown_origin_is_not_granted_cross_origin_access(): void
    {
        config()->set('cors.allowed_origins', ['https://mdm-2isy.com']);

        $response = $this->withHeaders([
            'Origin' => 'https://example.invalid',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/ping');

        $response->assertSuccessful();
        $this->assertNotSame(
            'https://example.invalid',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
