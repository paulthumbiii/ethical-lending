<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ifrs9_loan_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->tinyInteger('current_stage');
            $table->tinyInteger('previous_stage');
            $table->boolean('sicr_flag')->default(false);
            $table->string('transition_reason', 255);
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index('loan_id');
            $table->index('current_stage');
            $table->index('sicr_flag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ifrs9_loan_stages');
    }
};