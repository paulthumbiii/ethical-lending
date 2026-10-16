<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbk_regulatory_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reporting_period');
            $table->string('return_type');
            $table->json('payload');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbk_regulatory_returns');
    }
};