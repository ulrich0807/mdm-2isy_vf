<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('severity', 20)->default('warning')->after('type');
            $table->string('source_key', 150)->nullable()->unique()->after('severity');
        });

        DB::table('alerts')->update([
            'organization_id' => DB::raw('(SELECT organization_id FROM terminals WHERE terminals.id = alerts.terminal_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['severity', 'source_key']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
