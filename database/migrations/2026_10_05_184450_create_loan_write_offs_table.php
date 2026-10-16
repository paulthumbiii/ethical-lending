<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->onDelete('restrict');
            $table->decimal('written_off_principal', 12, 2);
            $table->decimal('written_off_interest', 12, 2);
            $table->decimal('written_off_fees', 12, 2);
            $table->decimal('total_written_off', 12, 2);
            $table->string('write_off_reason', 255);
            $table->foreignId('approved_by_user_id')->constrained('users')->onDelete('restrict');
            $table->timestamp('written_off_at');
            $table->decimal('post_write_off_recovered_amount', 12, 2)->default(0.00);
            $table->timestamps();

            $table->index('loan_id');
            $table->index('written_off_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_write_offs');
    }
};