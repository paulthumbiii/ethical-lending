<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrower_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->string('channel', 50);
            $table->string('opt_out_reason', 255)->nullable();
            $table->timestamp('opted_out_at')->useCurrent();

            $table->index('borrower_id');
            $table->index('channel');
            $table->unique(['borrower_id', 'channel'], 'idx_borrower_channel_opt_out');
            
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_opt_outs');
    }
};