<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promise_to_pays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->onDelete('cascade');
            $table->date('promised_date');
            $table->decimal('promised_amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->timestamp('honored_at')->nullable();
            $table->timestamps();

            $table->index('promised_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promise_to_pays');
    }
};