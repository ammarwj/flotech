<?php

namespace App\Http\Middleware;

/**
 * `event.perm.registration` — registrations index/store/update/import,
 * invoice/receipt/album, race periods & categories, participant discount
 * codes, race scan/report/check-ins.
 */
class RegistrationPermission extends EnsureOperatorPermission
{
    protected function category(): string
    {
        return 'registration';
    }
}
