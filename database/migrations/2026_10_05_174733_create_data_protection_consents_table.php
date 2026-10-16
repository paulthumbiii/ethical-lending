<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_protection_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('consent_type', 100);
            $table->boolean('is_granted');
            $table->string('ip_address', 45);
            $table->string('user_agent', 255);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index('borrower_id');
            $table->index('consent_type');
            $table->index('is_granted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_protection_consents');
    }
};