<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->nullable()->index();
                $table->string('student_number', 50)->unique();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->date('birthday')->nullable();
                $table->string('course', 150);
                $table->string('year_level', 50);
                $table->string('section', 50)->default('');
                $table->string('phone', 20)->default('');
                $table->enum('status', ['Active', 'Inactive'])->default('Active');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
