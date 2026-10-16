<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_waterfall_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('payment_reference')->index();
            $table->decimal('total_amount_received', 12, 2);
            $table->decimal('allocated_to_fees', 12, 2)->default(0.00);
            $table->decimal('allocated_to_interest', 12, 2)->default(0.00);
            $table->decimal('allocated_to_principal', 12, 2)->default(0.00);
            $table->decimal('overpayment_amount', 12, 2)->default(0.00);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_waterfall_logs');
    }
};