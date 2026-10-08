<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('artifact_type', 16)->default('apk')->after('chemin_apk');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn('artifact_type');
        });
    }
};
