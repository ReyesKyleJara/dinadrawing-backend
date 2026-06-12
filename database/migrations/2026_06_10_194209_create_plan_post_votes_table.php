<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_post_votes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_post_id')
                ->constrained('plan_posts')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unsignedInteger('option_index');

            $table->timestamps();

            $table->unique([
                'plan_post_id',
                'user_id',
                'option_index',
            ], 'unique_plan_post_user_option_vote');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_post_votes');
    }
};