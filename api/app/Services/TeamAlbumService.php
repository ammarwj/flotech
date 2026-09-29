<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Team;
use App\Support\RegistrationForm;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;

/**
 * Renders the printable "player album" — one page per team, laid out as the
 * paper form panitia already use: a two-logo masthead, then one ruled table
 * listing officials (A) and players (B), one row each with a 3x4 photo box and
 * a data-diri block.
 *
 * Synchronous like ExportController, not a queued job like IdCardService:
 * a handful of teams' worth of photos renders fast enough inline, and there
 * is no batch/zip step to justify a background worker.
 */
class TeamAlbumService
{
    public function __construct(protected PdfImageService $images) {}

    /**
     * The dompdf builder for one or more teams' album. Each team after the
     * first starts on a fresh page — see pdf/team-album.blade.php. Returns
     * the builder (not ->output() bytes) so the controller can call
     * ->download($name), which handles the Content-Disposition header
     * itself — see BillingDocumentService::pdf() for the same shape.
     */
    public function build(Event $event, Collection $teams): DomPdf
    {
        return Pdf::loadHTML($this->html($event, $teams))->setPaper('a4', 'portrait');
    }

    /**
     * The rendered sheet, before dompdf turns it into a page.
     *
     * Split out of build() so the layout itself is assertable: dompdf's output
     * is compressed streams, so a test holding the PDF bytes can only check
     * that *something* was produced — which is exactly the assertion that stays
     * green when a row stops being filled in.
     */
    public function html(Event $event, Collection $teams): string
    {
        $form = RegistrationForm::forEvent($event);

        // The organizer's logo and the venue line are the same on every page,
        // and both cost a fetch/re-encode — resolve them once per PDF, not
        // once per team.
        $shared = [
            'organizer_logo' => $this->images->dataUri($event->organization?->logo_url),
            'venue' => $this->venue($event),
        ];

        return view('pdf.team-album', [
            'event' => $event,
            'teams' => $teams->map(fn (Team $team) => $this->teamPayload($event, $team, $form, $shared)),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $shared
     * @return array<string, mixed>
     */
    protected function teamPayload(Event $event, Team $team, RegistrationForm $form, array $shared): array
    {
        $players = $team->players
            ->sortBy(fn ($p) => $p->jersey_number ?? PHP_INT_MAX)
            ->values();

        return [
            'name' => $team->name,
            'logo' => $this->images->dataUri($team->logo_url),
            'organizer_logo' => $shared['organizer_logo'],
            'venue' => $shared['venue'],
            'category' => $team->category?->name,
            'contact' => $this->contactLine($team),
            'players' => $players->map(fn ($player) => [
                'name' => $player->full_name,
                'jersey_number' => $player->jersey_number ?? '',
                'position' => Catalog::positionLabel($event->sport_type, $player->position) ?? '',
                'extra' => $form->albumRows('player', $player->custom_fields),
                'photo' => $this->images->dataUri($player->photo_url, 'photo'),
            ])->all(),
            'officials' => $team->officials->map(fn ($official) => [
                'name' => $official->full_name,
                'role' => Catalog::officialRoleLabel($event->sport_type, $official->role) ?? 'Ofisial',
                'extra' => $form->albumRows('official', $official->custom_fields),
                'photo' => $this->images->dataUri($official->photo_url, 'photo'),
            ])->all(),
        ];
    }

    /**
     * "Budi (0812…)" beside the club name, or just whichever half the team
     * filled in. Null when neither is set, so the sheet prints the club line
     * alone instead of an empty label — the form is photocopied and handed
     * out, and a stray "KONTAK :" with nothing after it reads as missing data
     * rather than as data the team never had to give.
     */
    protected function contactLine(Team $team): ?string
    {
        $name = trim((string) $team->contact_name);
        $phone = trim((string) $team->contact_phone);

        if ($name !== '' && $phone !== '') {
            return $name.' ('.$phone.')';
        }

        return $name !== '' ? $name : ($phone !== '' ? $phone : null);
    }

    /**
     * The "Kp. Cijawer Desa Cikancra …" line under the event name.
     *
     * Address first, falling back to the venue name: the printed masthead wants
     * a place, and an event that only named its venue still has one.
     */
    protected function venue(Event $event): ?string
    {
        $line = trim((string) ($event->location_address ?: $event->location_name));

        return $line !== '' ? $line : null;
    }
}
