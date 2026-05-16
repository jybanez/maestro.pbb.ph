<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maestro_application_id')->constrained('maestro_applications')->cascadeOnDelete();
            $table->string('worker_id')->unique();
            $table->string('host_name')->nullable();
            $table->string('queue_name')->nullable()->index();
            $table->unsignedBigInteger('process_id')->nullable();
            $table->string('status', 32)->default('starting')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('last_job_started_at')->nullable();
            $table->timestamp('last_job_finished_at')->nullable();
            $table->string('current_job_type')->nullable();
            $table->string('current_job_id')->nullable();
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->decimal('memory_mb', 10, 2)->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_workers');
    }
};
