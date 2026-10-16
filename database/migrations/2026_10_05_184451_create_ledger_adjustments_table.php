<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->string('adjustment_type', 50);
            $table->decimal('amount', 12, 2);
            $table->string('debit_account_code', 20);
            $table->string('credit_account_code', 20);
            $table->text('reason');
            $table->foreignId('authorized_by_user_id')->constrained('users')->onDelete('restrict');
            $table->timestamp('created_at')->useCurrent();

            $table->index('loan_id');
            $table->index('adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustments');
    }
};