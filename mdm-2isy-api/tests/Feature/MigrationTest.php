<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_contains_a_single_complete_logs_table(): void
    {
        $this->assertTrue(Schema::hasTable('logs'));
        $this->assertTrue(Schema::hasColumns('logs', [
            'id',
            'usr',
            'act',
            'cible',
            'typ',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_fresh_schema_contains_the_device_enrollment_domain(): void
    {
        foreach ([
            'organizations',
            'device_groups',
            'device_enrollment_tokens',
            'device_credentials',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('users', ['organization_id']));
        $this->assertTrue(Schema::hasColumns('terminals', [
            'organization_id',
            'device_group_id',
            'public_id',
            'device_uid',
            'serial_number',
            'manufacturer',
            'android_version',
            'storage_total_mb',
            'storage_free_mb',
            'agent_version',
            'enrollment_status',
            'enrolled_at',
            'last_seen_at',
        ]));

        $this->assertDatabaseHas('organizations', [
            'slug' => 'legacy-fleet',
            'active' => true,
        ]);
    }
}
