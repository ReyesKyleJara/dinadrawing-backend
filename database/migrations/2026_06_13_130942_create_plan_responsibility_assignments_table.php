<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_responsibility_assignments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('responsibility_item_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('manual_name')->nullable();

            // pending, accepted, declined
            $table->string('status', 20)->default('pending');

            // preassigned, claimed
            $table->string('source', 20)->default('preassigned');

            $table->timestamps();

            $table->foreign(
                'responsibility_item_id',
                'resp_assignment_item_fk'
            )
                ->references('id')
                ->on('plan_responsibility_items')
                ->cascadeOnDelete();

            $table->foreign(
                'user_id',
                'resp_assignment_user_fk'
            )
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->unique(
                ['responsibility_item_id', 'user_id'],
                'resp_assignment_user_unique'
            );

            $table->index(
                ['responsibility_item_id', 'status'],
                'resp_assignment_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_responsibility_assignments');
    }
};