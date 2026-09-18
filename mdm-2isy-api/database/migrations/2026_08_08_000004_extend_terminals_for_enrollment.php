<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->index()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->foreignId('device_group_id')
                ->nullable()
                ->constrained('device_groups')
                ->nullOnDelete();
            $table->uuid('public_id')->nullable();
            $table->string('device_uid', 191)->nullable();
            $table->string('serial_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('android_version')->nullable();
            $table->string('android_build')->nullable();
            $table->unsignedBigInteger('storage_total_mb')->nullable();
            $table->unsignedBigInteger('storage_free_mb')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('enrollment_status', 32)->default('legacy');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });

        $legacyOrganizationId = DB::table('organizations')
            ->where('slug', 'legacy-fleet')
            ->value('id');

        if ($legacyOrganizationId === null) {
            throw new RuntimeException('The legacy-fleet organization is required to migrate existing terminals.');
        }

        DB::table('terminals')
            ->whereNull('organization_id')
            ->update(['organization_id' => $legacyOrganizationId]);

        DB::table('terminals')
            ->select('id')
            ->whereNull('public_id')
            ->chunkById(500, function ($terminals): void {
                foreach ($terminals as $terminal) {
                    DB::table('terminals')
                        ->where('id', $terminal->id)
                        ->update(['public_id' => (string) Str::uuid()]);
                }
            });

        Schema::table('terminals', function (Blueprint $table) {
            $table->string('imei')->nullable()->change();
            $table->integer('batterie')->nullable()->default(null)->change();
            $table->uuid('public_id')->nullable(false)->change();
        });

        Schema::table('terminals', function (Blueprint $table) {
            $table->unique('public_id');
            $table->unique(
                ['organization_id', 'device_uid'],
                'terminals_org_device_uid_unique',
            );
            $table->index(
                ['organization_id', 'enrollment_status', 'last_seen_at'],
                'terminals_org_status_seen_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropUnique('terminals_public_id_unique');
            $table->dropUnique('terminals_org_device_uid_unique');
            $table->dropIndex('terminals_org_status_seen_idx');
            $table->dropForeign(['device_group_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex('terminals_organization_id_index');
        });

        DB::table('terminals')
            ->select('id')
            ->whereNull('imei')
            ->chunkById(500, function ($terminals): void {
                foreach ($terminals as $terminal) {
                    DB::table('terminals')
                        ->where('id', $terminal->id)
                        ->update(['imei' => 'rollback-'.Str::uuid()]);
                }
            });

        DB::table('terminals')
            ->whereNull('batterie')
            ->update(['batterie' => 100]);

        Schema::table('terminals', function (Blueprint $table) {
            $table->string('imei')->nullable(false)->change();
            $table->integer('batterie')->nullable(false)->default(100)->change();
        });

        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn([
                'organization_id',
                'device_group_id',
                'public_id',
                'device_uid',
                'serial_number',
                'manufacturer',
                'android_version',
                'android_build',
                'storage_total_mb',
                'storage_free_mb',
                'agent_version',
                'enrollment_status',
                'enrolled_at',
                'last_seen_at',
            ]);
        });
    }
};
