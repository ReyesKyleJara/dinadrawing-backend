<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->timestamp('voting_starts_at')->nullable()->after('ends_on');
            $table->timestamp('voting_ends_at')->nullable()->after('voting_starts_at');
            $table->boolean('is_voting_closed')->default(false)->after('voting_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->dropColumn([
                'voting_starts_at',
                'voting_ends_at',
                'is_voting_closed',
            ]);
        });
    }
};