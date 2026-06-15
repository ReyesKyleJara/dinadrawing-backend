<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')
                ->constrained('plans')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * equal  = all included members receive equal shares
             * custom = the admin manually assigns each share
             */
            $table->string('split_type', 20)
                ->default('equal');

            /*
             * Contribution tracking is optional.
             *
             * When disabled:
             * - Paid/Unpaid is hidden
             * - existing statuses remain stored
             *
             * When enabled again:
             * - previous statuses return
             */
            $table->boolean('contribution_tracking_enabled')
                ->default(false);

            /*
             * Members can mark only their own contribution as paid.
             */
            $table->boolean('allow_member_mark_paid')
                ->default(true);

            /*
             * Determines whether ordinary members can see
             * everybody's Paid/Unpaid status.
             */
            $table->boolean('show_status_to_members')
                ->default(true);

            /*
             * This value is also recalculated from expenses
             * by the backend whenever the budget is saved.
             */
            $table->decimal('total_estimated', 12, 2)
                ->default(0);

            /*
             * null means the budget is still a draft.
             */
            $table->timestamp('published_at')
                ->nullable();

            $table->timestamps();

            /*
             * One active budget plan per plan.
             */
            $table->unique('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_budgets');
    }
};