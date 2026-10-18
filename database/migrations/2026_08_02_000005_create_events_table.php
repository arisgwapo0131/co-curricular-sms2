<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('club_id')->index();
                $table->string('event_type', 50)->default('Club');
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->dateTime('event_date');
                $table->string('venue', 150);
                $table->integer('expected_attendees')->default(0);
                $table->string('attachment', 255)->nullable();
                $table->string('status', 50)->default('Pending SSC');
                $table->timestamp('created_at')->useCurrent();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->text('endorsement_notes')->nullable();
                $table->text('rejection_note')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
