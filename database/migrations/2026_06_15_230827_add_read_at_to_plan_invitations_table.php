<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'plan_invitations',
            function (Blueprint $table) {
                $table->timestamp('read_at')
                    ->nullable()
                    ->after('responded_at');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'plan_invitations',
            function (Blueprint $table) {
                $table->dropColumn('read_at');
            }
        );
    }
};