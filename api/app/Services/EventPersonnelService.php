<?php

namespace App\Services;

use App\Jobs\PurgeMediaJob;
use App\Models\Event;

/**
 * Referees and match staff, written the same way a team's bench is: the client
 * sends the whole list, and this is the one place that turns it into rows.
 *
 * Contract, identical to TeamRosterService::syncOfficials(): a row carrying an
 * `id` is an update, a row without one is new, and anything the client left out
 * is deleted. Kept as its own service rather than a method over there because
 * the two share no rule — a bench validates its roles against the sport's
 * catalogue, this list has no catalogue at all.
 */
class EventPersonnelService
{
    /**
     * @param  array<int, array<string, mixed>>  $personnel
     */
    public function sync(Event $event, array $personnel): void
    {
        $keepIds = [];

        foreach ($personnel as $order => $row) {
            $attrs = [
                'full_name' => $row['full_name'],
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
                $existing->update($attrs);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $event->personnel()->create($attrs)->id;
            }
        }

        $stale = $event->personnel()->whereKeyNot($keepIds)->pluck('photo_url')->all();
        $event->personnel()->whereKeyNot($keepIds)->delete();
        $this->purgePruned($stale, $event->personnel()->pluck('photo_url')->all());
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
