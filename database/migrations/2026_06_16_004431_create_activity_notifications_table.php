<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'activity_notifications',
            function (Blueprint $table) {
                $table->id();

                /*
                 * The user who will receive the notification.
                 */
                $table->foreignId('recipient_user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                /*
                 * The user who triggered the notification.
                 *
                 * Nullable in case the account is deleted
                 * or the notification is system-generated.
                 */
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                /*
                 * Examples:
                 *
                 * post_comment
                 * responsibility_assigned
                 * poll_reminder
                 * member_joined
                 * member_left
                 * budget_review
                 */
                $table->string('type', 50);

                /*
                 * Related plan.
                 */
                $table->foreignId('plan_id')
                    ->nullable()
                    ->constrained('plans')
                    ->cascadeOnDelete();

                /*
                 * Related post when applicable.
                 */
                $table->foreignId('plan_post_id')
                    ->nullable()
                    ->constrained('plan_posts')
                    ->cascadeOnDelete();

                /*
                 * Related comment when applicable.
                 */
                $table->foreignId('plan_post_comment_id')
                    ->nullable()
                    ->constrained('plan_post_comments')
                    ->cascadeOnDelete();

                /*
                 * Extra information such as:
                 *
                 * comment preview
                 * plan title
                 * post type
                 */
                $table->json('data')
                    ->nullable();

                $table->timestamp('read_at')
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'recipient_user_id',
                    'read_at',
                ]);

                $table->index([
                    'recipient_user_id',
                    'created_at',
                ]);

                $table->index([
                    'type',
                    'created_at',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'activity_notifications'
        );
    }
};