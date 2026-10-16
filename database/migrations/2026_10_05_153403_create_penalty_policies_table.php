<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->integer('grace_period_days')->default(3);
            $table->decimal('penalty_rate_daily', 5, 2);
            $table->decimal('fixed_late_fee', 12, 2)->default(0.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_policies');
    }
};