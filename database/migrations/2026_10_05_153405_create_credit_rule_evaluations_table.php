<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_rule_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained('loan_applications')->onDelete('cascade');
            $table->string('rule_identifier', 100);
            $table->string('rule_description', 255);
            $table->string('status', 20);
            $table->string('actual_value', 100);
            $table->string('threshold_value', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->index('loan_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_rule_evaluations');
    }
};