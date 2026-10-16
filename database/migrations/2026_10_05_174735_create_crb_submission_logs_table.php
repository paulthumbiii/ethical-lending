<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crb_submission_logs', function (Blueprint $table) {
            $table->id();
            $table->string('batch_month', 7);
            $table->string('bureau_name', 50);
            $table->integer('total_records_submitted');
            $table->integer('performing_records');
            $table->integer('defaulted_records');
            $table->string('file_path', 500);
            $table->string('status', 30)->default('generated');
            $table->timestamps();

            $table->index('batch_month');
            $table->index('bureau_name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crb_submission_logs');
    }
};