<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\WalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Release an event's held funds as soon as the organizer marks it finished,
 * rather than waiting for the hourly wallet:release sweep.
 */
class ReleaseEventFundsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $eventId) {}

    public function handle(WalletService $wallet): void
    {
        $event = Event::find($this->eventId);

        if ($event) {
            $wallet->releaseEvent($event);
        }
    }
}
