<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('id_number', 30)->unique();
            $table->string('kra_pin', 20)->nullable()->unique();
            $table->string('phone_number', 20)->unique();
            $table->string('email')->nullable();
            $table->decimal('credit_limit', 12, 2)->default(0.00);
            $table->string('risk_band', 10)->default('C');
            $table->boolean('is_blacklisted')->default(false);
            $table->timestamps();

            $table->index('id_number');
            $table->index('phone_number');
            $table->index('risk_band');
            $table->index('is_blacklisted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowers');
    }
};