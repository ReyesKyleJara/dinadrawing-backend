<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')
                ->constrained('plans')
                ->cascadeOnDelete();

            /*
             * The account being invited.
             */
            $table->foreignId('invited_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
             * Usually the Plan Admin who sent the invite.
             */
            $table->foreignId('invited_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * pending, accepted, or declined
             */
            $table->string('status', 20)
                ->default('pending');

            $table->timestamp('responded_at')
                ->nullable();

            $table->timestamps();

            /*
             * One invitation record per user per plan.
             *
             * If a declined invitation is sent again later,
             * the existing row can be reset to pending.
             */
            $table->unique([
                'plan_id',
                'invited_user_id',
            ]);

            $table->index([
                'invited_user_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_invitations');
    }
};