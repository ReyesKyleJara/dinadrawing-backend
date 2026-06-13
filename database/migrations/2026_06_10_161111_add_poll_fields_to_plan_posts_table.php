<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->string('post_type')->default('text')->after('user_id');

            $table->string('poll_question')->nullable()->after('image_path');
            $table->json('poll_options')->nullable()->after('poll_question');

            $table->boolean('allow_multiple')->default(false)->after('poll_options');
            $table->boolean('anonymous')->default(true)->after('allow_multiple');
            $table->boolean('allow_members_add_options')->default(false)->after('anonymous');

            $table->string('ends_on')->nullable()->after('allow_members_add_options');
        });
    }

    public function down(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->dropColumn([
                'post_type',
                'poll_question',
                'poll_options',
                'allow_multiple',
                'anonymous',
                'allow_members_add_options',
                'ends_on',
            ]);
        });
    }
};