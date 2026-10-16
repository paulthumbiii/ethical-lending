<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('full_name');
            $table->string('id_number', 30);
            $table->string('phone_number', 20);
            $table->decimal('guaranteed_amount', 12, 2);
            $table->string('verification_token', 64)->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index('id_number');
            $table->index('phone_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantors');
    }
};