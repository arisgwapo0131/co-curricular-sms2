<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('election_votes')) {
            Schema::create('election_votes', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('election_id')->index();
                $table->integer('user_id')->nullable();
                $table->string('ballot_token', 255)->nullable()->index();
                $table->text('ballot_data')->nullable();
                $table->text('votes_json');
                $table->dateTime('cast_at')->useCurrent();
                $table->dateTime('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_votes');
    }
};
