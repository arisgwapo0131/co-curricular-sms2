<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('elections')) {
            Schema::create('elections', function (Blueprint $table) {
                $table->increments('id');
                $table->string('election_code', 50)->unique();
                $table->unsignedInteger('club_id')->index();
                $table->string('scope', 50)->default('Club');
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('election_type', 100)->default('Student Governance');
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('closes_at')->nullable();
                $table->string('status', 50)->default('active')->index();
                $table->integer('eligible_voters')->default(0);
                $table->text('positions')->nullable();
                $table->unsignedInteger('created_by')->index();
                $table->dateTime('verified_at')->nullable();
                $table->unsignedInteger('verified_by')->nullable();
                $table->text('audit_notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};
