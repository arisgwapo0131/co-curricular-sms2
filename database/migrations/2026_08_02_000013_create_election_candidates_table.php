<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('election_candidates')) {
            Schema::create('election_candidates', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('election_id')->index();
                $table->integer('user_id')->nullable();
                $table->string('candidate_code', 50);
                $table->string('name', 150);
                $table->string('position', 100)->index();
                $table->string('party', 150)->nullable();
                $table->string('year_level', 50)->nullable();
                $table->string('program', 50)->nullable();
                $table->string('gwa', 20)->nullable();
                $table->text('platform_tag')->nullable();
                $table->text('achievements')->nullable();
                $table->integer('votes_count')->default(0);
                $table->tinyInteger('is_appointed')->default(0);
                $table->string('status', 50)->default('Active');
                $table->dateTime('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_candidates');
    }
};
