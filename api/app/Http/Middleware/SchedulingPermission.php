<?php

namespace App\Http\Middleware;

/**
 * `event.perm.scheduling` — schedule, draw, Swiss, knockout, bracket, matches,
 * standings, adjustments, leaderboard, discipline, lineup sheet, match report,
 * rubbers.
 *
 * `matches/{match}/confirm` is NEVER gated by this — that route keeps its own
 * `org.admin` middleware untouched, see routes/api.php.
 */
class SchedulingPermission extends EnsureOperatorPermission
{
    protected function category(): string
    {
        return 'scheduling';
    }
}
