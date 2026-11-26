<?php

namespace App\Http\Middleware;

/**
 * `event.perm.documents` — certificates, id cards, bibs (printing only, not
 * the org-scoped template CRUD), event personnel index/sync, photos, sponsors.
 */
class DocumentsPermission extends EnsureOperatorPermission
{
    protected function category(): string
    {
        return 'documents';
    }
}
