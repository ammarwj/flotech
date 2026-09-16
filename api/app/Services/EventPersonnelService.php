<?php

namespace App\Services;

use App\Jobs\PurgeMediaJob;
use App\Models\Event;
use App\Models\EventPersonnel;
use App\Models\User;
use App\Notifications\PersonnelInvited;

/**
 * Referees and match staff, written the same way a team's bench is: the client
 * sends the whole list, and this is the one place that turns it into rows.
 *
 * Contract, identical to TeamRosterService::syncOfficials(): a row carrying an
 * `id` is an update, a row without one is new, and anything the client left out
 * is deleted. Kept as its own service rather than a method over there because
 * the two share no rule — a bench validates its roles against the sport's
 * catalogue, this list has no catalogue at all.
 *
 * Since the crew got logins, this is also where accounts are provisioned. That
 * is a heavier job than it looks, because of what this endpoint is: a full-list
 * PUT that fires on *every* save of the personnel form. Anything here that is
 * not idempotent mails somebody their password again each time the organizer
 * fixes a typo three rows down.
 */
class EventPersonnelService
{
    /**
     * Mailed in the body of the invite, and only ever written on an account
     * this service creates. The user chose that trade-off explicitly, with the
     * other half attached: EnsurePasswordRotated makes it stop working the
     * moment it has been read. See the docblock on PersonnelInvited.
     */
    public const DEFAULT_PASSWORD = 'welcomefloevent1';

    /**
     * @param  array<int, array<string, mixed>>  $personnel
     */
    public function sync(Event $event, array $personnel): void
    {
        $keepIds = [];

        foreach ($personnel as $order => $row) {
            $email = $this->normalizeEmail($row['email'] ?? null);

            $attrs = [
                'full_name' => $row['full_name'],
                'email' => $email,
                'kind' => $row['kind'],
                'role_label' => $row['role_label'] ?? null,
                'photo_url' => $row['photo_url'] ?? null,
                // The order they were typed in is the order they are shown.
                'sort_order' => $order,
            ];

            $existing = ! empty($row['id'])
                ? $event->personnel()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                // Read before the write. `$attrs` already carries the new
                // address, so asking the model afterwards would compare the new
                // email with itself and call every save a change — which is
                // exactly the "re-mail everyone's password on every save" bug
                // this branch exists to prevent.
                $previousEmail = $existing->email;
                $previousUserId = $existing->user_id;

                $existing->update($attrs);
                $this->provision($event, $existing, $previousEmail, $previousUserId);
                $keepIds[] = $existing->id;
            } else {
                $person = $event->personnel()->create($attrs);
                $this->provision($event, $person, null, null);
                $keepIds[] = $person->id;
            }
        }

        // Rows only. The `users` rows they point at are deliberately left
        // standing: the same person referees other events, and deleting the
        // account would cut those rows too. Their access to *this* event dies
        // because the middleware looks for a personnel row, not because the
        // login vanished.
        $stale = $event->personnel()->whereKeyNot($keepIds)->pluck('photo_url')->all();
        $event->personnel()->whereKeyNot($keepIds)->delete();
        $this->purgePruned($stale, $event->personnel()->pluck('photo_url')->all());
    }

    /**
     * Give the row a login, or leave it exactly as it is.
     *
     * Four cases, and the third is the one that carries the weight:
     *
     * - no email            → unlink. Whoever was attached stops being crew.
     * - email unchanged and
     *   already linked      → do nothing, and send nothing. This is the common
     *                         case: every save of a form with five people in it.
     * - address belongs to
     *   an existing account → link it. The password is never touched — without
     *                         that rule, typing a stranger's address into a
     *                         personnel row would be an account-takeover
     *                         primitive. They get told they were assigned.
     * - nobody has it       → create the account with the default password and
     *                         mail it, flagged for rotation.
     *
     * `email_verified_at` is stamped on creation on purpose: the invitation
     * *is* the verification, since the password can only be read out of that
     * address's inbox.
     */
    protected function provision(Event $event, EventPersonnel $person, ?string $previousEmail, ?string $previousUserId): void
    {
        $email = $person->email;

        if ($email === null) {
            if ($previousUserId !== null) {
                $person->update(['user_id' => null]);
            }

            return;
        }

        if ($email === $previousEmail && $previousUserId !== null) {
            return;
        }

        $user = User::where('email', $email)->first();
        $created = false;

        if (! $user) {
            $user = new User;
            // forceFill, not create(): `must_change_password` is kept out of
            // $fillable so that nothing but this line and updatePassword() can
            // move it. `password` is cast 'hashed', so the plaintext default
            // goes in as plaintext — Hash::make here would store a hash of a
            // hash and lock the account out of its own invite.
            $user->forceFill([
                'full_name' => $person->full_name,
                'email' => $email,
                'password' => self::DEFAULT_PASSWORD,
                'role' => 'user',
                'default_mode' => 'officiating',
                'must_change_password' => true,
                'email_verified_at' => now(),
            ])->save();
            $created = true;
        }

        $person->update(['user_id' => $user->id]);

        // afterCommit for the same reason PurgeMediaJob has it below: the
        // whole sync runs inside a transaction, and a worker that picks the
        // mail up before the commit lands would link a reader to rows that do
        // not exist yet.
        $user->notify(
            (new PersonnelInvited($person, $event, $created, $created ? self::DEFAULT_PASSWORD : null))
                ->afterCommit()
        );
    }

    /** Blank, whitespace and "not sent at all" are one state: no account. */
    protected function normalizeEmail(mixed $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' ? $email : null;
    }

    /**
     * Delete the files of dropped rows — but only the ones no surviving row
     * still points at, since two rows may legitimately carry the same URL
     * (the same photo re-picked). Copied from TeamRosterService for the same
     * reason it exists there.
     *
     * @param  array<int, string|null>  $stale
     * @param  array<int, string|null>  $remaining
     */
    protected function purgePruned(array $stale, array $remaining): void
    {
        $gone = array_diff(array_filter($stale), array_filter($remaining));

        if ($gone !== []) {
            PurgeMediaJob::dispatch(array_values($gone))->afterCommit();
        }
    }
}
