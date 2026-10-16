<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_legal_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->onDelete('restrict');
            $table->string('contract_pdf_path', 500);
            $table->string('contract_sha256_hash', 64)->unique();
            $table->timestamp('digital_signature_timestamp');
            $table->string('qr_verification_code', 100)->unique();
            $table->timestamps();

            $table->index('contract_sha256_hash');
            $table->index('qr_verification_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_legal_contracts');
    }
};