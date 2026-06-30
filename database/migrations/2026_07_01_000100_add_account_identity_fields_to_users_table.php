<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'pbb_user_id')) {
                $table->string('pbb_user_id', 26)->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 40)->default('user')->after('password');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 40)->default('active')->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'pbb_user_id')) {
                $table->dropUnique(['pbb_user_id']);
                $table->dropColumn('pbb_user_id');
            }

            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
