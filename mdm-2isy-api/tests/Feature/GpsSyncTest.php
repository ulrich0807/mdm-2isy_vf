<?php

namespace Tests\Feature;

use Tests\TestCase;

class GpsSyncTest extends TestCase
{
    public function test_legacy_gps_sync_endpoint_is_no_longer_routed(): void
    {
        $this->postJson('/api/gps/1/sync', [
            'lat' => 91,
            'lng' => -181,
            'batt' => 101,
        ])->assertNotFound();
    }
}
