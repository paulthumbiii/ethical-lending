<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('product_code', 20)->unique();
            $table->decimal('min_principal', 12, 2);
            $table->decimal('max_principal', 12, 2);
            $table->integer('default_tenure_days');
            $table->string('interest_calculation_method', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};