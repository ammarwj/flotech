<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Event;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deletes the stored objects behind rows we are about to remove.
 *
 * Every upload mints a fresh object (UploadController), but nothing used to
 * delete one: rows carrying a `*_url` were dropped — often by FK cascade — and
 * the object stayed in the bucket with no row left naming its key. Once that
 * happens the orphan is unrecoverable, because the key only ever existed in the
 * row that got deleted.
 *
 * This is the single place that translates a stored value into an object key
 * and the single place that touches the disk, so the rules below live in one
 * spot rather than in each of the four delete paths.
 */
class MediaCleanupService
{
    /** Rows per whereIn chunk when collecting an event's team files. */
    private const CHUNK = 500;

    /**
     * Delete the objects behind the given stored values. Returns how many keys
     * were attempted (values that aren't ours are dropped, not counted).
     *
     * @param  iterable<int, string|null>  $values
     */
    public function purge(iterable $values): int
    {
        $keys = [];

        foreach ($values as $value) {
            $key = $this->keyFor($value);

            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        if ($keys === []) {
            return 0;
        }

        $keys = array_keys($keys);

        // Laravel loops the array and swallows UnableToDeleteFile per key, so a
        // file that is already gone doesn't strand the rest of the batch.
        $this->disk()->delete($keys);

        return count($keys);
    }

    /**
     * The object key behind a stored value, or null when it isn't ours to
     * delete.
     *
     * Both `photo_url` and a sponsor's `logo_url` are validated as plain
     * strings, so an organizer may legitimately paste a link to an image
     * hosted elsewhere. Those must come back null: we can't delete them, and a
     * looser rule that treated any string as one of our keys would aim a
     * delete at whatever path happened to fall out of the URL.
     */
    public function keyFor(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // UploadController::sign() hands this back when R2 isn't configured;
        // no object was ever written.
        if (Str::startsWith($value, 'mock://')) {
            return null;
        }

        foreach ([config('r2.public_url'), Storage::disk('public')->url('')] as $base) {
            $base = rtrim((string) $base, '/');

            if ($base !== '' && Str::startsWith($value, $base.'/')) {
                return ltrim(Str::after($value, $base), '/');
            }
        }

        // Bare keys, e.g. certificates.pdf_key. Anything else absolute belongs
        // to a host we don't own.
        return Str::contains($value, '://') ? null : ltrim($value, '/');
    }

    /**
     * Every file stored under an event.
     *
     * Must be called *before* the event is deleted: the children below hang off
     * `event_id` with cascadeOnDelete, so once the row is gone every key here
     * is gone with it and there is no model event left to react to.
     *
     * Deliberately excludes files that outlive the event even though they are
     * reachable from it — the organization's logo and banner, and a certificate
     * template's background, which belongs to the org and is shared by every
     * event that uses the template.
     *
     * @return array<int, string|null>
     */
    public function eventUrls(Event $event): array
    {
        return [
            $event->banner_url,
            ...$event->photos()->pluck('photo_url'),
            ...$event->sponsors()->pluck('logo_url'),
            ...$event->ticketOrders()->pluck('payment_proof_url'),
            ...Certificate::where('event_id', $event->id)->pluck('pdf_key'),
            ...$this->teamUrls($event->teams()->pluck('id')->all()),
        ];
    }

    /**
     * Every file stored under the given teams — their own logo and payment
     * proof plus the roster's photos and documents, which cascade from
     * `team_id`.
     *
     * eventUrls() goes through here rather than repeating the column list, so
     * dropping a category and deleting the whole event can't disagree about
     * what counts as a team's files.
     *
     * @param  array<int, string>  $teamIds
     * @return array<int, string|null>
     */
    public function teamUrls(array $teamIds): array
    {
        $urls = [];

        foreach (array_chunk($teamIds, self::CHUNK) as $chunk) {
            $urls = [
                ...$urls,
                ...DB::table('teams')->whereIn('id', $chunk)->pluck('logo_url'),
                ...DB::table('teams')->whereIn('id', $chunk)->pluck('payment_proof_url'),
                ...DB::table('players')->whereIn('team_id', $chunk)->pluck('photo_url'),
                ...DB::table('team_officials')->whereIn('team_id', $chunk)->pluck('photo_url'),
                ...DB::table('registration_documents')->whereIn('team_id', $chunk)->pluck('file_url'),
            ];
        }

        return $urls;
    }

    /**
     * The disk UploadController wrote to: R2 when configured, the local public
     * disk otherwise so development and tests exercise this same path.
     */
    protected function disk(): Filesystem
    {
        return Storage::disk(config('r2.key') ? 'r2' : 'public');
    }
}
