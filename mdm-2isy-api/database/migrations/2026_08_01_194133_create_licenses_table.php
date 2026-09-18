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
    Schema::create('licenses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('terminal_id')->constrained('terminals')->onDelete('cascade');
        $table->string('cle')->unique();
        $table->timestamp('date_activation')->nullable();
        $table->timestamp('date_expiration')->nullable(); // Gérera le décompte des 365 jours
        $table->boolean('est_valide')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
