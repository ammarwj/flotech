<?php

namespace App\Http\Controllers\Api\Public\Concerns;

use App\Models\Event;
use App\Models\Organization;

trait ResolvesPublicEvent
{
    /**
     * Resolve a published event by org + event slug (404 for drafts).
     *
     * Shared so every public entry point — the page payload and the view
     * beacon — agrees on what "publicly visible" means. A draft that counted
     * views would report traffic for a page nobody can open.
     */
    protected function resolve(string $orgSlug, string $eventSlug): Event
    {
        $org = Organization::where('slug', $orgSlug)->firstOrFail();

        return $org->events()
            ->where('slug', $eventSlug)
            ->where('status', '!=', 'draft')
            ->firstOrFail();
    }
}
