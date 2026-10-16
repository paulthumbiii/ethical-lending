<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restructuring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restructuring_proposal_id')
                  ->constrained('restructuring_proposals')
                  ->onDelete('cascade');
            $table->integer('installment_number');
            $table->date('due_date');
            $table->decimal('installment_amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->index('status');
            // FIX: Explicitly name the composite index to keep it under 64 characters
            $table->index(['restructuring_proposal_id', 'installment_number'], 'idx_restruct_sched_prop_inst');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restructuring_schedules');
    }
};