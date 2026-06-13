<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('plan_posts', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });
    }
};