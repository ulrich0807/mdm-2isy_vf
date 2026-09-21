<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profils', function (Blueprint $table) {
            $table->boolean('no_wifi')->default(false)->after('no_bt');
            $table->boolean('no_data')->default(false)->after('no_wifi');
            $table->boolean('no_airplane')->default(false)->after('no_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profils', function (Blueprint $table) {
            $table->dropColumn(['no_wifi', 'no_data', 'no_airplane']);
        });
    }
};
