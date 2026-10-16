<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_duplum_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->onDelete('cascade');
            $table->timestamp('defaulted_at');
            $table->decimal('default_principal_snapshot', 12, 2);
            $table->decimal('total_post_default_interest', 12, 2)->default(0.00);
            $table->decimal('total_post_default_penalties', 12, 2)->default(0.00);
            $table->decimal('statutory_cap_limit', 12, 2);
            $table->boolean('is_frozen')->default(false);
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->index('is_frozen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_duplum_tracking');
    }
};