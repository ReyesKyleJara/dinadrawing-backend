<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('budget_id')
                ->constrained('plan_budgets')
                ->cascadeOnDelete();

            $table->string('name', 150);

            /*
             * Optional explanation, such as:
             * "Food and drinks for 15 people"
             */
            $table->text('note')
                ->nullable();

            $table->decimal('estimated_amount', 12, 2);

            /*
             * Preserves the order selected by the admin.
             */
            $table->unsignedInteger('position')
                ->default(0);

            $table->timestamps();

            $table->index([
                'budget_id',
                'position',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_expenses');
    }
};