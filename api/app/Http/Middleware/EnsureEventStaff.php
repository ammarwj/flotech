<?php

namespace App\Http\Middleware;

use App\Models\EventPersonnel;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows an officiating route to `staff` — the people who record scores, cards,
 * scorers and assists.
 *
 * Its own class rather than one parameterised middleware taking the kind as an
 * argument, following EnsureOrgAdmin next door. A route line then reads as the
 * role it admits, and the two roles cannot be swapped by editing a string in a
 * route file. Always stack this after `event.personnel`, which is what puts the
 * row on the request.
 */
class EnsureEventStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var EventPersonnel|null $person */
        $person = $request->attributes->get('personnel');

        if (! $person || $person->kind !== 'staff') {
            return ApiResponse::error('Hanya staf pertandingan yang bisa mengakses ini.', null, 403);
        }

        return $next($request);
    }
}
