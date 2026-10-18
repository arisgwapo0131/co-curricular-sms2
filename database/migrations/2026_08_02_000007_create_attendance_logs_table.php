<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->dateTime('check_in')->useCurrent();
                $table->enum('method', ['QR', 'RFID', 'Manual', 'QR_SELF'])->default('QR');
                $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('override_reason')->nullable();
                $table->string('status', 50)->default('Valid');

                $table->unique(['event_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
