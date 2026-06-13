<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->string('responsibility_title')
                ->nullable()
                ->after('is_voting_closed');

            $table->string('responsibility_mode', 30)
                ->nullable()
                ->after('responsibility_title');

            $table->boolean('responsibility_allow_member_items')
                ->default(false)
                ->after('responsibility_mode');

            $table->boolean('responsibility_show_progress')
                ->default(true)
                ->after('responsibility_allow_member_items');

            $table->boolean('responsibility_is_finalized')
                ->default(false)
                ->after('responsibility_show_progress');
        });
    }

    public function down(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->dropColumn([
                'responsibility_title',
                'responsibility_mode',
                'responsibility_allow_member_items',
                'responsibility_show_progress',
                'responsibility_is_finalized',
            ]);
        });
    }
};