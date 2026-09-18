<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('lics', function (Blueprint $table) {
            $table->id();
            $table->string('cle')->unique(); // Ex: MDM-2026-XYZ89
            $table->unsignedBigInteger('term_id')->nullable(); // Null si non assignée
            $table->string('statut')->default('Vierge'); // Vierge, Active, Expirée
            $table->timestamp('exp_le')->nullable(); // Date d'expiration
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('lics');
    }
};