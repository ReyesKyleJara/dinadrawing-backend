<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->timestamp('post_event_checked_at')
                ->nullable()
                ->after('is_deleted');

            $table->timestamp('completed_at')
                ->nullable()
                ->after('post_event_checked_at');

            $table->timestamp('post_event_prompt_snoozed_until')
                ->nullable()
                ->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'post_event_checked_at',
                'completed_at',
                'post_event_prompt_snoozed_until',
            ]);
        });
    }
};
