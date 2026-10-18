<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('org_announcements')) {
            Schema::create('org_announcements', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('club_id')->nullable()->index();
                $table->enum('scope', ['System', 'Council', 'Club'])->default('Club');
                $table->unsignedInteger('author_id')->index();
                $table->string('title', 250);
                $table->string('category', 100)->default('General');
                $table->enum('priority', ['Normal', 'Important', 'Urgent'])->default('Normal');
                $table->enum('status', ['Draft', 'Published', 'Active', 'Archived'])->default('Published');
                $table->boolean('is_pinned')->default(false);
                $table->dateTime('expires_at')->nullable();
                $table->text('content');
                $table->string('target_group', 100)->default('All Members');
                $table->string('channels', 100)->default('In-App');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('org_announcements');
    }
};
