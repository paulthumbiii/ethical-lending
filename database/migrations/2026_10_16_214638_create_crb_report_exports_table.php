<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crb_report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('bureau_name');
            $table->string('export_reference');
            $table->integer('records_count')->default(0);
            $table->json('payload_summary')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crb_report_exports');
    }
};