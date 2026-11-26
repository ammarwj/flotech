<?php

namespace App\Http\Middleware;

/** `event.perm.ticketing` — ticket categories, scanning, check-ins, sales, reports. */
class TicketingPermission extends EnsureOperatorPermission
{
    protected function category(): string
    {
        return 'ticketing';
    }
}
