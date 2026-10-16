<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_cooldown_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('lock_type', 50)->default('COLLECTIONS_SMS');
            $table->timestamp('locked_until');
            $table->string('reason', 255);
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('locked_until');
            $table->index(['borrower_id', 'lock_type', 'locked_until'], 'idx_borrower_cooldown_lock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_cooldown_locks');
    }
};