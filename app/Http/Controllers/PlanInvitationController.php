<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanInvitationController extends Controller
{
    public function store(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $currentUser = $request->user();

        if (
            $plan->is_deleted ||
            $plan->is_archived
        ) {
            return response()->json([
                'success' => false,
                'code' => 'plan_unavailable',
                'message' =>
                    'This plan is not currently accepting invitations.',
            ], 422);
        }

        $isPlanMember = $plan
            ->members()
            ->where(
                'users.id',
                $currentUser->id
            )
            ->exists();

        if (!$isPlanMember) {
            return response()->json([
                'success' => false,
                'code' => 'not_plan_member',
                'message' =>
                    'You are not allowed to invite people to this plan.',
            ], 403);
        }

        $username = strtolower(
            trim(
                preg_replace(
                    '/^@+/',
                    '',
                    (string) $request->input(
                        'username',
                        ''
                    )
                )
            )
        );

        if (
            strlen($username) < 3 ||
            strlen($username) > 20 ||
            !preg_match(
                '/^[A-Za-z0-9._]+$/',
                $username
            )
        ) {
            return response()->json([
                'success' => false,
                'code' => 'invalid_username',
                'message' =>
                    'Enter a valid username.',
            ], 422);
        }

        $invitedUser = User::query()
            ->whereRaw(
                'LOWER(username) = ?',
                [$username]
            )
            ->first();

        if ($invitedUser === null) {
            return response()->json([
                'success' => false,
                'code' => 'user_not_found',
                'message' => 'User not found.',
            ], 404);
        }

        if (
            (int) $invitedUser->id ===
            (int) $currentUser->id
        ) {
            return response()->json([
                'success' => false,
                'code' => 'cannot_invite_self',
                'message' =>
                    'You cannot invite yourself.',
            ], 422);
        }

        $alreadyMember = $plan
            ->members()
            ->where(
                'users.id',
                $invitedUser->id
            )
            ->exists();

        if ($alreadyMember) {
            return response()->json([
                'success' => false,
                'code' => 'already_member',
                'message' =>
                    'This user is already a member.',
            ], 409);
        }

        $existingInvitation = PlanInvitation::query()
            ->where(
                'plan_id',
                $plan->id
            )
            ->where(
                'invited_user_id',
                $invitedUser->id
            )
            ->first();

        if (
            $existingInvitation !== null &&
            $existingInvitation->status === 'pending'
        ) {
            return response()->json([
                'success' => false,
                'code' => 'invitation_already_sent',
                'message' =>
                    'Invitation already sent.',
            ], 409);
        }

        $invitation = DB::transaction(
            function () use (
                $plan,
                $invitedUser,
                $currentUser,
                $existingInvitation
            ): PlanInvitation {
                if ($existingInvitation !== null) {
                    $existingInvitation->update([
                        'invited_by' =>
                            $currentUser->id,
                        'status' => 'pending',
                        'responded_at' => null,
                        'read_at' => null,
                    ]);

                    return $existingInvitation->fresh();
                }

                return PlanInvitation::create([
                    'plan_id' =>
                        $plan->id,
                    'invited_user_id' =>
                        $invitedUser->id,
                    'invited_by' =>
                        $currentUser->id,
                    'status' => 'pending',
                    'responded_at' => null,
                    'read_at' => null,
                ]);
            }
        );

        return response()->json([
            'success' => true,
            'code' => 'invited',
            'message' => 'Invited!',
            'invitation' =>
                $this->serializeInvitation(
                    $invitation->load([
                        'plan',
                        'inviter',
                    ])
                ),
        ], 201);
    }

    public function index(
        Request $request
    ): JsonResponse {
        $invitations = PlanInvitation::query()
            ->with([
                'plan',
                'inviter',
            ])
            ->where(
                'invited_user_id',
                $request->user()->id
            )
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'unread_count' =>
                $invitations
                    ->whereNull('read_at')
                    ->count(),
            'invitations' =>
                $invitations
                    ->map(
                        fn (
                            PlanInvitation $invitation
                        ) =>
                            $this->serializeInvitation(
                                $invitation
                            )
                    )
                    ->values(),
        ]);
    }

    public function markRead(
        Request $request,
        PlanInvitation $invitation
    ): JsonResponse {
        $this->ensureInvitationOwner(
            $request,
            $invitation
        );

        if ($invitation->read_at === null) {
            $invitation->update([
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Invitation marked as read.',
            'invitation' =>
                $this->serializeInvitation(
                    $invitation
                        ->fresh()
                        ->load([
                            'plan',
                            'inviter',
                        ])
                ),
        ]);
    }

    public function respond(
        Request $request,
        PlanInvitation $invitation
    ): JsonResponse {
        $this->ensureInvitationOwner(
            $request,
            $invitation
        );

        $validated = $request->validate([
            'response' => [
                'required',
                'in:accepted,declined',
            ],
        ]);

        if ($invitation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'code' =>
                    'invitation_already_responded',
                'message' =>
                    'This invitation has already been answered.',
            ], 409);
        }

        $plan = $invitation->plan;

        if (
            $plan === null ||
            $plan->is_deleted
        ) {
            return response()->json([
                'success' => false,
                'code' => 'plan_unavailable',
                'message' =>
                    'This plan is no longer available.',
            ], 404);
        }

        $responseValue =
            $validated['response'];

        DB::transaction(
            function () use (
                $request,
                $invitation,
                $plan,
                $responseValue
            ): void {
                if (
                    $responseValue === 'accepted'
                ) {
                    $alreadyMember = $plan
                        ->members()
                        ->where(
                            'users.id',
                            $request->user()->id
                        )
                        ->exists();

                    if (!$alreadyMember) {
                        $plan->members()->attach(
                            $request->user()->id,
                            [
                                'role' => 'member',
                            ]
                        );
                    }
                }

                $invitation->update([
                    'status' =>
                        $responseValue,
                    'responded_at' => now(),
                    'read_at' =>
                        $invitation->read_at ??
                        now(),
                ]);
            }
        );

        $message =
            $responseValue === 'accepted'
                ? 'Invitation accepted.'
                : 'Invitation declined.';

        $responseData = [
            'success' => true,
            'code' =>
                $responseValue === 'accepted'
                    ? 'invitation_accepted'
                    : 'invitation_declined',
            'message' => $message,
            'invitation' =>
                $this->serializeInvitation(
                    $invitation
                        ->fresh()
                        ->load([
                            'plan',
                            'inviter',
                        ])
                ),
        ];

        if ($responseValue === 'accepted') {
            $responseData['plan'] = $plan->load([
                'admin',
                'members',
            ]);
        }

        return response()->json($responseData);
    }

    private function ensureInvitationOwner(
        Request $request,
        PlanInvitation $invitation
    ): void {
        abort_unless(
            (int) $invitation
                ->invited_user_id ===
            (int) $request->user()->id,
            403,
            'You are not allowed to access this invitation.'
        );
    }

    private function serializeInvitation(
        PlanInvitation $invitation
    ): array {
        $plan = $invitation->plan;
        $inviter = $invitation->inviter;

        return [
            'id' =>
                (int) $invitation->id,
            'status' =>
                (string) $invitation->status,
            'is_read' =>
                $invitation->read_at !== null,
            'read_at' =>
                optional(
                    $invitation->read_at
                )->toISOString(),
            'responded_at' =>
                optional(
                    $invitation->responded_at
                )->toISOString(),
            'created_at' =>
                optional(
                    $invitation->created_at
                )->toISOString(),

            'plan' => $plan === null
                ? null
                : [
                    'id' =>
                        (int) $plan->id,
                    'title' =>
                        (string) $plan->title,
                    'banner_color' =>
                        $plan->banner_color,
                    'status' =>
                        $plan->status,
                    'is_archived' =>
                        (bool) $plan->is_archived,
                    'is_deleted' =>
                        (bool) $plan->is_deleted,
                ],

            'inviter' => $inviter === null
                ? null
                : [
                    'id' =>
                        (int) $inviter->id,
                    'name' =>
                        (string) $inviter->name,
                    'username' =>
                        $inviter->username,
                    'profile_photo_path' =>
                        $inviter
                            ->profile_photo_path,
                ],
        ];
    }
}
