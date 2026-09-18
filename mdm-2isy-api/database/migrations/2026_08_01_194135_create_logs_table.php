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
    Schema::create('logs', function (Blueprint $table) {
        $table->id();
        $table->string('usr'); // Qui a fait l'action
        $table->string('act'); // Quelle action
        $table->string('cible')->nullable(); // Sur quel terminal
        $table->string('typ')->default('primary'); // Couleur du badge (primary, warning, danger)
        $table->timestamps(); // Gère automatiquement la date et l'heure
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
