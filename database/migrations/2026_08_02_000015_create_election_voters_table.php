<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('election_voters')) {
            Schema::create('election_voters', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('election_id');
                $table->integer('user_id')->index();
                $table->string('eligibility_status', 50)->default('Eligible');
                $table->dateTime('voted_at')->useCurrent();

                $table->unique(['election_id', 'user_id'], 'uq_election_voter');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_voters');
    }
};
