<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_worker_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maestro_worker_id')->constrained('maestro_workers')->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('worker_id')->index();
            $table->string('event_type')->index();
            $table->string('queue_name')->nullable()->index();
            $table->string('job_type')->nullable();
            $table->string('job_id')->nullable();
            $table->string('outcome')->nullable();
            $table->text('notes')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_worker_events');
    }
};
