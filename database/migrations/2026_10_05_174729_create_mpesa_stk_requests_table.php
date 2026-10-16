<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_stk_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('restrict');
            $table->string('merchant_request_id', 100);
            $table->string('checkout_request_id', 100)->unique();
            $table->string('phone_number', 20);
            $table->decimal('amount', 12, 2);
            $table->string('mpesa_receipt_number', 50)->nullable()->unique();
            $table->integer('result_code')->nullable();
            $table->string('result_desc', 255)->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->index('loan_id');
            $table->index('checkout_request_id');
            $table->index('mpesa_receipt_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_stk_requests');
    }
};