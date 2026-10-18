<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mfa_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('email', 150);
            $table->string('code', 10);
            $table->enum('purpose', ['login', 'password_reset', 'account_update'])->default('login');
            $table->dateTime('expires_at');
            $table->tinyInteger('is_used')->default(0);
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(5);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'purpose', 'is_used', 'expires_at']);
            $table->index('code');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'last_mfa_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dateTime('last_mfa_verified_at')->nullable()->after('last_password_change');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mfa_codes');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'last_mfa_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_mfa_verified_at');
            });
        }
    }
};
