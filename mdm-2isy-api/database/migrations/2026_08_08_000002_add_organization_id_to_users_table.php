<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->index()
                ->constrained('organizations')
                ->nullOnDelete();
        });

        $legacyOrganizationId = DB::table('organizations')
            ->where('slug', 'legacy-fleet')
            ->value('id');

        if ($legacyOrganizationId === null) {
            throw new RuntimeException('The legacy-fleet organization is required to migrate existing users.');
        }

        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('organization_id')
            ->update(['organization_id' => $legacyOrganizationId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_organization_id_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
};
