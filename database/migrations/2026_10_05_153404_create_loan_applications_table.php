<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number', 50)->unique();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('restrict');
            $table->decimal('requested_principal', 12, 2);
            $table->integer('requested_tenure_days');
            $table->string('status', 30)->default('submitted');
            $table->foreignId('underwriter_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('application_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};