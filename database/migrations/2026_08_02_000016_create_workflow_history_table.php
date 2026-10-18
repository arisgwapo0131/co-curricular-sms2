<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workflow_history')) {
            Schema::create('workflow_history', function (Blueprint $table) {
                $table->id();
                $table->string('module', 80);
                $table->unsignedBigInteger('record_id');
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50);
                $table->string('action', 80);
                $table->unsignedInteger('performed_by')->nullable()->index();
                $table->text('remarks')->nullable();
                $table->dateTime('created_at')->useCurrent();

                $table->index(['module', 'record_id'], 'idx_workflow_record');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_history');
    }
};
