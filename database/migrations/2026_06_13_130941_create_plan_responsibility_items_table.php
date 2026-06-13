<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_responsibility_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_post_id')
                ->constrained('plan_posts')
                ->cascadeOnDelete();

            /*
             * Used by person_based mode when the row belongs
             * to an actual registered plan member.
             */
            $table->foreignId('member_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * person_based:
             *   title = person's display name
             *
             * role_task_based:
             *   title = role or task name
             */
            $table->string('title');

            /*
             * True when the person was typed manually and
             * does not have an account/user ID.
             */
            $table->boolean('is_manual')
                ->default(false);

            /*
             * Used mainly by person_based mode.
             */
            $table->text('contribution')
                ->nullable();

            /*
             * Used mainly by role_task_based mode.
             */
            $table->unsignedSmallInteger('slots')
                ->default(1);

            $table->unsignedInteger('position')
                ->default(0);

            $table->timestamps();

            $table->index([
                'plan_post_id',
                'position',
            ]);

            $table->unique([
                'plan_post_id',
                'member_user_id',
            ], 'responsibility_item_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_responsibility_items');
    }
};