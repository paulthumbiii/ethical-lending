<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->onDelete('cascade');
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('dpd_bucket', 20);
            $table->foreignId('assigned_agent_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('case_status', 30)->default('open');
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();

            $table->index('dpd_bucket');
            $table->index('case_status');
            $table->index('last_contacted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_cases');
    }
};