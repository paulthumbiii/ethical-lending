<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crb_dispute_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->string('dispute_reference', 100)->unique();
            $table->string('bureau_name', 50);
            $table->text('dispute_reason');
            $table->string('status', 30)->default('under_review');
            $table->timestamp('lock_expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('loan_id');
            $table->index('dispute_reference');
            $table->index('status');
            $table->index('lock_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crb_dispute_locks');
    }
};