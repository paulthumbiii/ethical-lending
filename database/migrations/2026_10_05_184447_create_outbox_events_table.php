<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_name', 100);
            $table->json('payload');
            $table->string('previous_event_hash', 64);
            $table->string('current_event_hash', 64)->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('event_name');
            $table->index('is_published');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};