<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('terminal_id')
                ->constrained('terminals')
                ->cascadeOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type', 32);
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('queued');
            // Only a scoped SHA-256 digest is persisted. Keeping the digest in
            // lowercase hexadecimal also makes raw keys case-sensitive on both
            // SQLite and case-insensitive MySQL collations.
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'status'],
                'device_commands_org_status_idx',
            );
            $table->index(
                ['terminal_id', 'status'],
                'device_commands_terminal_status_idx',
            );
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
