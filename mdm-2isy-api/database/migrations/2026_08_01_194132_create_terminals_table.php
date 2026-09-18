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
    Schema::create('terminals', function (Blueprint $table) {
        $table->id();
        $table->string('imei')->unique(); // Identifiant matériel unique
        $table->string('livreur')->nullable();
        $table->string('modele')->nullable();
        $table->integer('batterie')->default(100);
        $table->string('statut')->default('Hors ligne');
        
        // Coordonnées GPS
        $table->decimal('lat', 10, 8)->nullable();
        $table->decimal('lng', 11, 8)->nullable();
        
        // États des périphériques (Contrôle matériel)
        $table->boolean('camera_active')->default(true);
        $table->boolean('wifi_active')->default(true);
        $table->boolean('bluetooth_active')->default(true);
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
