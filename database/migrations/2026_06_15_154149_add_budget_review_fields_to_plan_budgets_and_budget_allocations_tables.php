<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_budgets', function (Blueprint $table) {
            $table->boolean('needs_review')
                ->default(false)
                ->after('published_at');

            $table->string('review_reason')
                ->nullable()
                ->after('needs_review');

            $table->json('review_context')
                ->nullable()
                ->after('review_reason');

            $table->timestamp('reviewed_at')
                ->nullable()
                ->after('review_context');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->boolean('is_former_member')
                ->default(false)
                ->after('marked_paid_by');

            $table->string('former_member_name')
                ->nullable()
                ->after('is_former_member');

            $table->timestamp('member_left_at')
                ->nullable()
                ->after('former_member_name');
        });
    }

    public function down(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->dropColumn([
                'is_former_member',
                'former_member_name',
                'member_left_at',
            ]);
        });

        Schema::table('plan_budgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');

            $table->dropColumn([
                'needs_review',
                'review_reason',
                'review_context',
                'reviewed_at',
            ]);
        });
    }
};