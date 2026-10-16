<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_withholding_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->string('fee_name', 100);
            $table->decimal('fee_charged', 12, 2);
            $table->decimal('excise_rate', 5, 2)->default(20.00);
            $table->decimal('excise_duty_amount', 12, 2);
            $table->string('remittance_status', 30)->default('accrued');
            $table->timestamp('remitted_at')->nullable();
            $table->timestamps();

            $table->index('loan_id');
            $table->index('remittance_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_withholding_ledgers');
    }
};