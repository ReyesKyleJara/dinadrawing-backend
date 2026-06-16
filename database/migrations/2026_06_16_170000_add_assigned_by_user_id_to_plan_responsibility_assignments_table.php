<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'plan_responsibility_assignments',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('assigned_by_user_id')
                    ->nullable()
                    ->after('user_id');

                $table->foreign(
                    'assigned_by_user_id',
                    'resp_assignment_assigned_by_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index(
                    'assigned_by_user_id',
                    'resp_assignment_assigned_by_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'plan_responsibility_assignments',
            function (Blueprint $table): void {
                $table->dropForeign(
                    'resp_assignment_assigned_by_fk'
                );

                $table->dropIndex(
                    'resp_assignment_assigned_by_idx'
                );

                $table->dropColumn('assigned_by_user_id');
            }
        );
    }
};
