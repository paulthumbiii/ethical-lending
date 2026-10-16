<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_statement_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('file_path', 500);
            $table->string('pdf_password_hash', 255)->nullable();
            $table->string('parsing_status', 30)->default('pending');
            $table->decimal('total_inflows_detected', 12, 2)->default(0.00);
            $table->decimal('total_outflows_detected', 12, 2)->default(0.00);
            $table->integer('gambling_transaction_count')->default(0);
            $table->integer('fuliza_deduction_count')->default(0);
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('parsing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_statement_uploads');
    }
};