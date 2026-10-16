<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->string('source_provider', 50)->default('SAFARICOM_MPESA');
            $table->string('status', 30)->default('processing');
            $table->json('response_payload')->nullable();
            $table->timestamp('locked_until');
            $table->timestamps();

            $table->index('idempotency_key');
            $table->index('status');
            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_idempotency_keys');
    }
};