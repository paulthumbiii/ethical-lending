<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
            $table->index('is_locked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};