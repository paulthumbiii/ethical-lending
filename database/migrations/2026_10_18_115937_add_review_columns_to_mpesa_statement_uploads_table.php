<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_statement_uploads', function (Blueprint $table) {
            $table->foreignId('loan_application_id')
                ->nullable()
                ->after('borrower_id')
                ->constrained('loan_applications')
                ->nullOnDelete();
            $table->string('original_name', 255)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->text('pdf_password_encrypted')->nullable();
            $table->string('review_status', 30)->default('awaiting_review');
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->json('flags')->nullable();
            $table->date('valid_until')->nullable();

            $table->index('file_sha256');
            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_statement_uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_application_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropIndex(['file_sha256']);
            $table->dropIndex(['review_status']);
            $table->dropColumn([
                'original_name',
                'file_size',
                'file_sha256',
                'pdf_password_encrypted',
                'review_status',
                'reviewed_at',
                'review_notes',
                'flags',
                'valid_until',
            ]);
        });
    }
};