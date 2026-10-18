<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('club_memberships')) {
            Schema::create('club_memberships', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('club_id')->index();
                $table->unsignedInteger('user_id')->index();
                $table->string('role', 50)->default('Member');
                $table->enum('status', ['Active', 'Pending', 'Rejected', 'Returned'])->default('Pending');
                $table->timestamp('joined_at')->useCurrent();
                $table->unsignedInteger('approved_by')->nullable();
                $table->string('letter_intent', 255)->nullable();
                $table->string('letter_endorsement', 255)->nullable();
                $table->string('adviser_review', 50)->default('Pending Adviser');
                $table->string('ssc_review', 50)->default('Pending SSC');
                $table->text('review_notes')->nullable();

                $table->unique(['club_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('club_memberships');
    }
};
