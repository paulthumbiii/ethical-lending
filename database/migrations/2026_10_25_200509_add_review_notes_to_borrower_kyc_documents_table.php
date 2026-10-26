<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrower_kyc_documents', function (Blueprint $table): void {
            $table->text('review_notes')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('borrower_kyc_documents', function (Blueprint $table): void {
            $table->dropColumn('review_notes');
        });
    }
};