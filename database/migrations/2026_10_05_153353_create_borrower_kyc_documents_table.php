<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrower_kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('document_type', 50);
            $table->string('file_path', 500);
            $table->string('verification_status', 30)->default('pending');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('verification_status');
            $table->index(['borrower_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_kyc_documents');
    }
};