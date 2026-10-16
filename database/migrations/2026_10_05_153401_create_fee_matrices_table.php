<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_matrices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->string('fee_name', 100);
            $table->string('calculation_type', 30);
            $table->decimal('fee_amount', 12, 2);
            $table->decimal('fee_percentage', 5, 2);
            $table->boolean('is_excise_duty_applicable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_matrices');
    }
};