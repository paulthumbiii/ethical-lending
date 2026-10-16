<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number', 50)->unique();
            $table->foreignId('loan_application_id')->unique()->constrained('loan_applications')->onDelete('restrict');
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('restrict');
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('restrict');
            $table->decimal('principal_disbursed', 12, 2);
            $table->decimal('annual_interest_rate', 5, 2);
            $table->integer('tenure_days');
            $table->string('status', 30)->default('pending');
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('defaulted_at')->nullable();
            $table->boolean('is_in_duplum_locked')->default(false);
            $table->timestamps();

            $table->index('loan_number');
            $table->index('borrower_id');
            $table->index('status');
            $table->index('due_date');
            $table->index('defaulted_at');
            $table->index('is_in_duplum_locked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};