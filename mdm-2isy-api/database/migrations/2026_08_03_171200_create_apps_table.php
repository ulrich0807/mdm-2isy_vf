<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
 public function up()
{
    Schema::create('apps', function (Blueprint $table) {
        $table->id();
        $table->string('nom');
        $table->string('pkg'); // Package Android (ex: com.whatsapp)
        $table->string('type')->default('blanche'); // 'blanche' ou 'noire'
        $table->string('ver')->nullable();
        $table->string('chemin_apk')->nullable(); // Pour stocker le fichier
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
