<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->integer('installment_number');
            $table->date('due_date');
            $table->decimal('principal_due', 12, 2);
            $table->decimal('interest_due', 12, 2);
            $table->decimal('fees_due', 12, 2);
            $table->decimal('total_installment_due', 12, 2);
            $table->decimal('principal_paid', 12, 2)->default(0.00);
            $table->decimal('interest_paid', 12, 2)->default(0.00);
            $table->decimal('fees_paid', 12, 2)->default(0.00);
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->index('due_date');
            $table->index('status');
            $table->index(['loan_id', 'installment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_schedules');
    }
};