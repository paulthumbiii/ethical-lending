<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_facilities', function (Blueprint $table) {
            $table->id();
            $table->string('investor_name', 100);
            $table->string('facility_code', 30)->unique();
            $table->decimal('total_facility_amount', 12, 2);
            $table->decimal('drawn_amount', 12, 2)->default(0.00);
            $table->decimal('annual_cost_of_funds', 5, 2);
            $table->date('start_date');
            $table->date('maturity_date');
            $table->timestamps();

            $table->index('facility_code');
            $table->index(['start_date', 'maturity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_facilities');
    }
};