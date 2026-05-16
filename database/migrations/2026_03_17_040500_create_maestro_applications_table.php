<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('app_code')->unique();
            $table->string('display_name');
            $table->string('environment')->default('local');
            $table->string('base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_applications');
    }
};
