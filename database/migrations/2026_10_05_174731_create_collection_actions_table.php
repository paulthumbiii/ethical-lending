<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->onDelete('cascade');
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('action_type', 30);
            $table->text('message_payload');
            $table->boolean('is_compliant_hours')->default(true);
            $table->string('gateway_status', 30);
            $table->timestamp('created_at')->useCurrent();

            $table->index('collection_case_id');
            $table->index('action_type');
            $table->index('is_compliant_hours');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_actions');
    }
};