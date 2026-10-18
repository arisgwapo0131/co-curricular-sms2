<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clubs')) {
            Schema::create('clubs', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 20)->unique();
                $table->string('name', 150);
                $table->enum('category', ['Academic', 'Cultural', 'Sports', 'Advocacy', 'Religious'])->default('Academic');
                $table->string('sub_category', 150)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('adviser_user_id')->nullable()->index();
                $table->string('adviser_name', 150)->default('Unassigned');
                $table->enum('status', ['Active', 'Pending Charter', 'Suspended'])->default('Active');
                $table->string('program', 150)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
