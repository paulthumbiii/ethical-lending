<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrower_financial_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->unique()->constrained('borrowers')->onDelete('cascade');
            $table->decimal('monthly_income', 12, 2);
            $table->decimal('monthly_expenses', 12, 2);
            $table->decimal('mpesa_average_monthly_inflow', 12, 2)->default(0.00);
            $table->decimal('existing_debt_obligations', 12, 2)->default(0.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_financial_profiles');
    }
};