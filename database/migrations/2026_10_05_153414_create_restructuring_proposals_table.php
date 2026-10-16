<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restructuring_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->string('proposal_code', 50)->unique();
            $table->string('status', 30)->default('offered');
            $table->decimal('forgiven_interest', 12, 2)->default(0.00);
            $table->decimal('forgiven_penalties', 12, 2)->default(0.00);
            $table->decimal('capitalized_principal', 12, 2);
            $table->decimal('new_interest_rate', 5, 2);
            $table->integer('tenure_extension_days');
            $table->decimal('repayment_ratio_snapshot', 5, 2);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('proposal_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restructuring_proposals');
    }
};