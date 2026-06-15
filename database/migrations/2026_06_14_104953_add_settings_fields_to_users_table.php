<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add persistent profile and notification settings
     * to the users table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table
                ->string('profile_photo_path')
                ->nullable()
                ->after('username');

            $table
                ->timestamp('username_changed_at')
                ->nullable()
                ->after('profile_photo_path');

            $table
                ->boolean('email_reminders')
                ->default(false)
                ->after('username_changed_at');

            $table
                ->boolean('push_notifications')
                ->default(true)
                ->after('email_reminders');

            $table
                ->boolean('in_app_alerts')
                ->default(true)
                ->after('push_notifications');
        });
    }

    /**
     * Remove the added settings fields.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'profile_photo_path',
                'username_changed_at',
                'email_reminders',
                'push_notifications',
                'in_app_alerts',
            ]);
        });
    }
};