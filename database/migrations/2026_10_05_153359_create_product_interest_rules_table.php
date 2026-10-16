<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_interest_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->string('tier_name', 50);
            $table->decimal('annual_rate', 5, 2);
            $table->decimal('monthly_rate', 5, 2);
            $table->string('compounding_frequency', 30)->default('none');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_interest_rules');
    }
};