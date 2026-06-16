<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $indexName): bool
    {
        $databaseName = DB::connection()->getDatabaseName();

        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$databaseName, 'budget_allocations', $indexName]
        ) !== null;
    }

    private function foreignKeyExists(string $foreignKeyName): bool
    {
        $databaseName = DB::connection()->getDatabaseName();

        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [
                $databaseName,
                'budget_allocations',
                $foreignKeyName,
                'FOREIGN KEY',
            ]
        ) !== null;
    }

    public function up(): void
    {
        // The budget_id foreign key may rely on the composite unique index.
        // Give budget_id its own index before removing that composite index.
        if (!$this->indexExists('budget_allocations_budget_id_index')) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD INDEX budget_allocations_budget_id_index (budget_id)'
            );
        }

        // A previous failed attempt may already have removed this foreign key.
        if ($this->foreignKeyExists('budget_allocations_user_id_foreign')) {
            DB::statement(
                'ALTER TABLE budget_allocations DROP FOREIGN KEY budget_allocations_user_id_foreign'
            );
        }

        if ($this->indexExists('budget_allocations_budget_id_user_id_unique')) {
            DB::statement(
                'ALTER TABLE budget_allocations DROP INDEX budget_allocations_budget_id_user_id_unique'
            );
        }

        DB::statement(
            'ALTER TABLE budget_allocations MODIFY user_id BIGINT UNSIGNED NULL'
        );

        if (!Schema::hasColumn('budget_allocations', 'manual_name')) {
            Schema::table('budget_allocations', function (Blueprint $table) {
                $table->string('manual_name', 150)
                    ->nullable()
                    ->after('user_id');
            });
        }

        if (!$this->indexExists('budget_allocations_user_id_index')) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD INDEX budget_allocations_user_id_index (user_id)'
            );
        }

        if (!$this->foreignKeyExists('budget_allocations_user_id_foreign')) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD CONSTRAINT budget_allocations_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL'
            );
        }

        if (!$this->indexExists('budget_allocations_budget_id_user_id_unique')) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD UNIQUE INDEX budget_allocations_budget_id_user_id_unique (budget_id, user_id)'
            );
        }
    }

    public function down(): void
    {
        DB::table('budget_allocations')
            ->whereNull('user_id')
            ->delete();

        if ($this->foreignKeyExists('budget_allocations_user_id_foreign')) {
            DB::statement(
                'ALTER TABLE budget_allocations DROP FOREIGN KEY budget_allocations_user_id_foreign'
            );
        }

        if ($this->indexExists('budget_allocations_budget_id_user_id_unique')) {
            DB::statement(
                'ALTER TABLE budget_allocations DROP INDEX budget_allocations_budget_id_user_id_unique'
            );
        }

        if (Schema::hasColumn('budget_allocations', 'manual_name')) {
            Schema::table('budget_allocations', function (Blueprint $table) {
                $table->dropColumn('manual_name');
            });
        }

        DB::statement(
            'ALTER TABLE budget_allocations MODIFY user_id BIGINT UNSIGNED NOT NULL'
        );

        if (!$this->foreignKeyExists('budget_allocations_user_id_foreign')) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD CONSTRAINT budget_allocations_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
            );
        }

        if (!$this->indexExists('budget_allocations_budget_id_user_id_unique')) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD UNIQUE INDEX budget_allocations_budget_id_user_id_unique (budget_id, user_id)'
            );
        }
    }
};
