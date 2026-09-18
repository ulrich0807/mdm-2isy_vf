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
    Schema::create('profils', function (Blueprint $table) {
        $table->id();
        $table->string('nom');
        $table->boolean('kiosk')->default(false);
        $table->string('app_kiosk')->nullable();
        $table->boolean('no_cam')->default(false);
        $table->boolean('no_usb')->default(false);
        $table->boolean('no_bt')->default(false);
        $table->boolean('pin_fort')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profils');
    }
};
