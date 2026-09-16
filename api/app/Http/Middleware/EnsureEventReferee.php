<?php

namespace App\Http\Middleware;

use App\Models\EventPersonnel;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows an officiating route to `referee` — the people who approve a team's
 * lineup before it can be printed.
 *
 * Twin of EnsureEventStaff; see its docblock for why these are two thin classes
 * instead of one taking the kind as an argument. Always stack after
 * `event.personnel`.
 */
class EnsureEventReferee
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var EventPersonnel|null $person */
        $person = $request->attributes->get('personnel');

        if (! $person || $person->kind !== 'referee') {
            return ApiResponse::error('Hanya wasit yang bisa mengakses ini.', null, 403);
        }

        return $next($request);
    }
}
