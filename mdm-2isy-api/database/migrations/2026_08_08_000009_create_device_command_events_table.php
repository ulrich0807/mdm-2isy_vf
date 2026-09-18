<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_command_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_command_id')
                ->constrained('device_commands')
                ->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(
                ['device_command_id', 'created_at'],
                'device_command_events_command_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_command_events');
    }
};
