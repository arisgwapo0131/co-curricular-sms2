<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('budget_requests')) {
            Schema::create('budget_requests', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('club_id')->index();
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->text('line_items')->nullable();
                $table->decimal('amount', 10, 2);
                $table->decimal('recommended_amount', 10, 2)->nullable();
                $table->decimal('final_approved_amount', 10, 2)->nullable();
                $table->string('status', 50)->default('Pending Adviser');
                $table->unsignedInteger('requested_by')->index();
                $table->text('notes')->nullable();
                $table->dateTime('disbursed_at')->nullable();
                $table->string('disbursement_reference', 100)->nullable();
                $table->unsignedInteger('disbursed_by')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_requests');
    }
};
