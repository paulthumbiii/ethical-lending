<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maker_checker_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maker_user_id')->constrained('users')->onDelete('restrict');
            $table->string('action_type', 100);
            $table->string('target_entity_type', 100);
            $table->unsignedBigInteger('target_entity_id');
            $table->json('payload');
            $table->string('status', 30)->default('pending');
            $table->foreignId('checker_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('checker_notes')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index('action_type');
            $table->index('status');
            $table->index(['target_entity_type', 'target_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maker_checker_requests');
    }
};