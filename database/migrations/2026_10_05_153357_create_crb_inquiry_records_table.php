<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crb_inquiry_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('bureau_name', 50);
            $table->integer('credit_score');
            $table->integer('open_accounts_count');
            $table->integer('defaulted_accounts_count');
            $table->decimal('total_npl_amount', 12, 2)->default(0.00);
            $table->string('inquiry_reference', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['borrower_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crb_inquiry_records');
    }
};