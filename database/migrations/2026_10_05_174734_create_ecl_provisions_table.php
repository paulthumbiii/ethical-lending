<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecl_provisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->date('reporting_date');
            $table->decimal('exposure_at_default', 12, 2);
            $table->decimal('probability_of_default', 5, 4);
            $table->decimal('loss_given_default', 5, 4);
            $table->decimal('calculated_ecl', 12, 2);
            $table->timestamps();

            $table->index('loan_id');
            $table->index('reporting_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecl_provisions');
    }
};