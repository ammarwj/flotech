<?php

namespace App\Support;

use App\Models\Team;

/**
 * Deep links from an email back into the web app.
 *
 * Every mail wants one, and every one of them is `frontend_url` + a path the
 * Next.js router owns — so the paths live here rather than being re-derived (and
 * eventually mistyped) in eight notification classes.
 */
class MailLinks
{
    public static function base(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    /** The manager's view of their own team. */
    public static function team(Team $team): string
    {
        return self::base().'/participant/teams/'.$team->id;
    }

    /** The organizer's registration queue for an event. */
    public static function registrations(string $eventId): string
    {
        return self::base().'/organizer/events/'.$eventId.'/registrations';
    }

    public static function subscription(): string
    {
        return self::base().'/organizer/subscription';
    }

    public static function wallet(): string
    {
        return self::base().'/organizer/wallet';
    }
}
