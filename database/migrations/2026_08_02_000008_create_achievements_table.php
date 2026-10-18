<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('achievements')) {
            Schema::create('achievements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
                $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 250);
                $table->string('competition', 250);
                $table->date('award_date');
                $table->string('proof_file', 300)->nullable();
                $table->enum('status', ['Pending', 'Verified', 'Rejected'])->default('Pending');
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
