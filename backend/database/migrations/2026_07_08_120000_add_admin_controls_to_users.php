<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('is_active');
            $table->string('banned_reason')->nullable()->after('banned_at');
            $table->boolean('must_change_password')->default(false)->after('banned_reason');
            $table->timestamp('tours_reset_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'banned_reason', 'must_change_password', 'tours_reset_at']);
        });
    }
};
