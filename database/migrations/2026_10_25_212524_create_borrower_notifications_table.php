<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrower_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('kind', 50);
            $table->string('title', 120);
            $table->string('body', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['borrower_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_notifications');
    }
};