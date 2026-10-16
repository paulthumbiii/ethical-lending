<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_b2c_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('restrict');
            $table->string('originator_conversation_id', 100)->nullable()->unique();
            $table->string('conversation_id', 100)->nullable();
            $table->string('transaction_id', 100)->nullable()->unique();
            $table->string('recipient_phone', 20);
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('initiated');
            $table->string('response_code', 20)->nullable();
            $table->text('response_description')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();

            $table->index('loan_id');
            $table->index('status');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_b2c_disbursements');
    }
};