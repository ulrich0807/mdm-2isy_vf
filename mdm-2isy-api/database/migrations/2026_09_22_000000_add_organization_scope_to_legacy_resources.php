<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lics', 'apps', 'profils', 'logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }

        DB::table('lics')
            ->whereNotNull('term_id')
            ->update([
                'organization_id' => DB::raw('(SELECT organization_id FROM terminals WHERE terminals.id = lics.term_id)'),
            ]);

        $legacyOrganizationId = DB::table('organizations')
            ->where('slug', 'legacy-fleet')
            ->value('id');

        if ($legacyOrganizationId !== null) {
            foreach (['lics', 'apps', 'profils', 'logs'] as $tableName) {
                DB::table($tableName)
                    ->whereNull('organization_id')
                    ->update(['organization_id' => $legacyOrganizationId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['lics', 'apps', 'profils', 'logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
