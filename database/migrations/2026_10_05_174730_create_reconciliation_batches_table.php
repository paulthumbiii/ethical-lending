<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_batches', function (Blueprint $table) {
            $table->id();
            $table->date('batch_date')->unique();
            $table->integer('total_mpesa_records');
            $table->integer('total_system_records');
            $table->integer('matched_records');
            $table->integer('unreconciled_discrepancies');
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->index('batch_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_batches');
    }
};