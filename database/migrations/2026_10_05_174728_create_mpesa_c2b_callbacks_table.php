<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_c2b_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_type', 50);
            $table->string('trans_id', 50)->unique();
            $table->string('trans_time', 30);
            $table->decimal('trans_amount', 12, 2);
            $table->string('business_short_code', 20);
            $table->string('bill_ref_number', 50);
            $table->string('msisdn', 20);
            $table->string('first_name', 50)->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index('trans_id');
            $table->index('bill_ref_number');
            $table->index('msisdn');
            $table->index('is_reconciled');
            $table->unique(['trans_id', 'msisdn'], 'idx_c2b_trans_msisdn_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_c2b_callbacks');
    }
};