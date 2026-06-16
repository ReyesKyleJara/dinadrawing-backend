<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('banner_image_path')
                ->nullable()
                ->after('banner_color');

            $table->string('theme_color', 20)
                ->default('#F2B73F')
                ->after('banner_image_path');
        });

        Schema::table('plan_posts', function (Blueprint $table) {
            $table->string('poll_kind', 20)
                ->default('general')
                ->after('poll_question');

            $table->unsignedInteger('finalized_option_index')
                ->nullable()
                ->after('is_voting_closed');

            $table->timestamp('finalized_at')
                ->nullable()
                ->after('finalized_option_index');

            $table->timestamp('applied_to_plan_at')
                ->nullable()
                ->after('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->dropColumn([
                'poll_kind',
                'finalized_option_index',
                'finalized_at',
                'applied_to_plan_at',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'banner_image_path',
                'theme_color',
            ]);
        });
    }
};
