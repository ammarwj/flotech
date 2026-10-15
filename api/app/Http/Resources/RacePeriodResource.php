<?php

namespace App\Http\Resources;

use App\Models\RacePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin RacePeriod
 */
class RacePeriodResource extends JsonResource
{
    /**
     * The window in force right now, resolved ONCE by the caller.
     *
     * Passed in rather than asked per row, and not for performance alone: the
     * question "which period is now" is RacePricing's, it needs the event's
     * whole list to answer (one window wins an overlap), and a resource that
     * asked it per row would both lazy-load the event N times and let two rows
     * answer differently. The caller resolves it with RacePricing::periodAt()
     * and hands the id down — the same reason the public event page resolves
     * `playerStats` once and threads it into every panel.
     */
    public function __construct($resource, private ?string $currentPeriodId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @param  iterable<RacePeriod>  $periods
     */
    public static function listOf(iterable $periods, ?string $currentPeriodId): array
    {
        return collect($periods)
            ->map(fn (RacePeriod $period) => new self($period, $currentPeriodId))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'sort_order' => $this->sort_order,
            // Published rather than left for the client to derive from the two
            // timestamps above, the same reasoning as
            // `effective_payment_method` and `registration_is_open`: two
            // readers of one rule drift, and here the drift is a page that
            // highlights early bird while the server charges last call.
            'is_current' => $this->currentPeriodId === $this->id,
            // Whether the window has run out, which is not the same question
            // and is why both are published: a page shows a past period greyed
            // and a future one as "mulai 12 Jan", and only one of the two can
            // ever be `is_current`.
            'has_ended' => $this->ends_at->lt(Carbon::now()),
        ];
    }
}
