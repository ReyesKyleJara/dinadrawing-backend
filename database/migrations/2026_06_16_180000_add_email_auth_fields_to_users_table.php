<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'email_reminders_enabled_at')) {
                $table->timestamp('email_reminders_enabled_at')
                    ->nullable()
                    ->after('email_reminders');
            }

            if (!Schema::hasColumn('users', 'oauth_provider')) {
                $table->string('oauth_provider', 40)
                    ->nullable()
                    ->after('email_reminders_enabled_at');
            }

            if (!Schema::hasColumn('users', 'oauth_uid')) {
                $table->string('oauth_uid')
                    ->nullable()
                    ->after('oauth_provider');
            }

            if (!Schema::hasColumn('users', 'oauth_avatar_url')) {
                $table->text('oauth_avatar_url')
                    ->nullable()
                    ->after('oauth_uid');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'oauth_provider') &&
                Schema::hasColumn('users', 'oauth_uid')) {
                $table->unique(
                    ['oauth_provider', 'oauth_uid'],
                    'users_oauth_provider_uid_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            try {
                $table->dropUnique('users_oauth_provider_uid_unique');
            } catch (Throwable) {
                // The index may not exist on older local databases.
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'email_reminders_enabled_at')
                    ? 'email_reminders_enabled_at'
                    : null,
                Schema::hasColumn('users', 'oauth_provider')
                    ? 'oauth_provider'
                    : null,
                Schema::hasColumn('users', 'oauth_uid')
                    ? 'oauth_uid'
                    : null,
                Schema::hasColumn('users', 'oauth_avatar_url')
                    ? 'oauth_avatar_url'
                    : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
