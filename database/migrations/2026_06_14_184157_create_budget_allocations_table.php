<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('budget_id')
                ->constrained('plan_budgets')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
             * Included members participate in the budget division.
             * Excluded members remain visible for transparency,
             * but receive no planned share.
             */
            $table->boolean('is_included')
                ->default(true);

            $table->decimal('planned_share', 12, 2)
                ->default(0);

            /*
             * Used only when contribution tracking is enabled.
             */
            $table->boolean('is_paid')
                ->default(false);

            $table->timestamp('paid_at')
                ->nullable();

            /*
             * The user who changed the status to Paid.
             *
             * This may be:
             * - the member themselves
             * - the Plan Admin
             */
            $table->foreignId('marked_paid_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /*
             * Each plan member may appear only once
             * inside one budget.
             */
            $table->unique([
                'budget_id',
                'user_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_allocations');
    }
};