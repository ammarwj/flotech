<?php

namespace App\Services;

use App\Exceptions\EventOperatorException;
use App\Mail\EventOperatorInvitedMail;
use App\Models\Event;
use App\Models\EventOperator;
use App\Models\EventOperatorInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The one writer of `event_operators` and `event_operator_invites` — same
 * role `EventPersonnelService` plays for `event_personnel`: a single place
 * that moves these rows through their states, so no second writer can drift
 * from the rules written here.
 *
 * Unlike EventPersonnelService's full-list PUT, this is a one-row-at-a-time
 * API (invite/resend/revoke/accept/updatePermissions/remove) — an organizer
 * invites people one at a time, and a stale client re-submitting a list of
 * operators it loaded minutes ago must never be able to silently drop one.
 */
class EventOperatorService
{
    /** How long an invite stays clickable before it must be resent. */
    public const EXPIRES_AFTER_DAYS = 7;

    /**
     * @param  array<string, bool>  $permissions  Keyed by EventOperator::PERMISSIONS.
     */
    public function invite(Event $event, string $email, array $permissions, User $invitedBy): EventOperatorInvite
    {
        $email = $this->normalizeEmail($email);

        return DB::transaction(function () use ($event, $email, $permissions, $invitedBy) {
            // Expire/revoke any pending invite for this (event, email) first —
            // the partial unique index would reject a second pending row
            // anyway, but this is what keeps the old one from going stale and
            // confusing whoever reads the invites list.
            $event->operatorInvites()
                ->where('email', $email)
                ->where('status', EventOperatorInvite::STATUS_PENDING)
                ->update(['status' => EventOperatorInvite::STATUS_REVOKED]);

            $invite = $event->operatorInvites()->create([
                'email' => $email,
                'can_ticketing' => $permissions['ticketing'] ?? false,
                'can_scheduling' => $permissions['scheduling'] ?? false,
                'can_registration' => $permissions['registration'] ?? false,
                'can_documents' => $permissions['documents'] ?? false,
                'status' => EventOperatorInvite::STATUS_PENDING,
                'token' => $this->generateToken(),
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
                'invited_by' => $invitedBy->id,
            ]);

            Mail::to($email)->queue((new EventOperatorInvitedMail($invite))->afterCommit());

            return $invite;
        });
    }

    /**
     * New token (old one dead), extended expiry. Does not touch permissions —
     * those are edited through `updatePermissions()` once accepted, or by
     * revoking and sending a fresh invite before that.
     */
    public function resend(EventOperatorInvite $invite): EventOperatorInvite
    {
        if ($invite->status !== EventOperatorInvite::STATUS_PENDING) {
            throw new EventOperatorException('Undangan ini sudah tidak berlaku.', null, 422);
        }

        return DB::transaction(function () use ($invite) {
            $invite->update([
                'token' => $this->generateToken(),
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ]);

            Mail::to($invite->email)->queue((new EventOperatorInvitedMail($invite))->afterCommit());

            return $invite;
        });
    }

    public function revoke(EventOperatorInvite $invite): EventOperatorInvite
    {
        if ($invite->status !== EventOperatorInvite::STATUS_PENDING) {
            throw new EventOperatorException('Undangan ini sudah tidak berlaku.', null, 422);
        }

        $invite->update(['status' => EventOperatorInvite::STATUS_REVOKED]);

        return $invite;
    }

    /**
     * Turns a usable invite into an `EventOperator` row for `$user`.
     *
     * > **Invariant: an invite is addressed to an address, accepted by an
     * > account that proves it.** `strtolower` on both sides — `normalize()`
     * > already lowercases what gets stored, but a user's own email is never
     * > forced through the same normalizer, so this compares defensively
     * > rather than assuming it.
     */
    public function accept(EventOperatorInvite $invite, User $user): EventOperator
    {
        if (! $invite->isUsable()) {
            throw new EventOperatorException('Undangan ini sudah tidak berlaku atau sudah kedaluwarsa.', null, 422);
        }

        if (strtolower($invite->email) !== strtolower($user->email)) {
            throw new EventOperatorException(
                'Undangan ini ditujukan ke alamat email lain. Masuk dengan email yang diundang untuk menerimanya.',
                null,
                403,
            );
        }

        return DB::transaction(function () use ($invite, $user) {
            $operator = EventOperator::query()->updateOrCreate(
                ['event_id' => $invite->event_id, 'user_id' => $user->id],
                [
                    'can_ticketing' => $invite->can_ticketing,
                    'can_scheduling' => $invite->can_scheduling,
                    'can_registration' => $invite->can_registration,
                    'can_documents' => $invite->can_documents,
                    'invited_by' => $invite->invited_by,
                ],
            );

            $invite->update([
                'status' => EventOperatorInvite::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            return $operator;
        });
    }

    /**
     * @param  array<string, bool>  $permissions  Keyed by EventOperator::PERMISSIONS.
     */
    public function updatePermissions(EventOperator $operator, array $permissions): EventOperator
    {
        $operator->update([
            'can_ticketing' => $permissions['ticketing'] ?? $operator->can_ticketing,
            'can_scheduling' => $permissions['scheduling'] ?? $operator->can_scheduling,
            'can_registration' => $permissions['registration'] ?? $operator->can_registration,
            'can_documents' => $permissions['documents'] ?? $operator->can_documents,
        ]);

        return $operator;
    }

    public function remove(EventOperator $operator): void
    {
        $operator->delete();
    }

    protected function generateToken(): string
    {
        return Str::random(64);
    }

    protected function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
