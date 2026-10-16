<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ussd_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 100)->unique();
            $table->string('phone_number', 20);
            $table->string('service_code', 30);
            $table->string('current_menu_node', 50)->default('ROOT');
            $table->json('session_data')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_sessions');
    }
};