<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\EventPersonnel;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the event a referee or match staff is working on, and proves they
 * are on its crew. The event and their personnel row are left on the request as
 * the `event` and `personnel` attributes, the same way TenantScope hands down
 * `organization`.
 *
 * This is the whole reason task accounts are not `organization_members`. That
 * table would have been less code — but TenantScope admits *every* member, so a
 * referee holding a row there would reach the wallet, the billing pages and
 * event deletion, and the only thing standing between them and it would be
 * somebody remembering to hide it. Out of the table, that surface is closed
 * structurally.
 *
 * **Do not set the `organization` attribute here.** The temptation is real: one
 * line and every existing organizer controller would run unchanged for a
 * referee. That line is exactly what un-decides the paragraph above — once the
 * attribute exists, a single route that drifts into this group hands the whole
 * organizer API to the crew.
 *
 * Membership is read from `user_id`, never from `email`: an address is what
 * somebody typed into a form, an account is what the holder can prove.
 */
class EventPersonnelScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = auth('api')->id();
        $eventId = $request->route('event');

        $person = EventPersonnel::query()
            ->where('event_id', is_string($eventId) ? $eventId : '')
            ->where('user_id', $userId)
            ->first();

        // One message for "no such event" and for "not on its crew". The
        // difference is not the caller's to learn: a signed-in stranger probing
        // ids would otherwise map out which events exist.
        if (! $person) {
            return ApiResponse::error('Kamu bukan petugas event ini.', null, 403);
        }

        $event = Event::find($person->event_id);

        if (! $event) {
            return ApiResponse::error('Kamu bukan petugas event ini.', null, 403);
        }

        $request->attributes->set('event', $event);
        $request->attributes->set('personnel', $person);

        return $next($request);
    }
}
