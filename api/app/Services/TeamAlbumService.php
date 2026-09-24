<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Team;
use App\Support\RegistrationForm;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
    /**
     * Field keys/labels we look for in the event's registration form to fill
     * the two rows the database has no column for: "Tempat Lahir" and "Alamat".
     *
     * Matched loosely (substring, case-insensitive, against both the key and
     * the label) because the form builder lets organizers name their own
     * fields — `alamat`, `alamat_rumah`, `address` all mean the same row on
     * this sheet. Nothing found leaves the row blank, which is the point: the
     * printed sheet is meant to be finished by hand.
     *
     * @var array<string, list<string>>
     */
    protected const FIELD_HINTS = [
        'birth_place' => ['tempat_lahir', 'tempatlahir', 'birth_place', 'birthplace', 'tempat lahir', 'pob'],
        'address' => ['alamat', 'address', 'domisili'],
    ];

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
            'sport_label' => Catalog::sport($event->sport_type)['name'] ?? null,
        ];

        return view('pdf.team-album', [
            'event' => $event,
            'sportLabel' => $shared['sport_label'],
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
            'players' => $players->map(fn ($player) => [
                'name' => $player->full_name,
                'jersey_number' => $player->jersey_number ?? '',
                'position' => Catalog::positionLabel($event->sport_type, $player->position) ?? '',
                'birth' => $this->birthLine(
                    $this->answer($form->playerFields, $player->custom_fields, 'birth_place'),
                    $player->date_of_birth,
                ),
                'address' => $this->answer($form->playerFields, $player->custom_fields, 'address'),
                'photo' => $this->images->dataUri($player->photo_url, 'photo'),
            ])->all(),
            'officials' => $team->officials->map(fn ($official) => [
                'name' => $official->full_name,
                'role' => Catalog::officialRoleLabel($event->sport_type, $official->role) ?? 'Ofisial',
                'birth' => $this->birthLine(
                    $this->answer($form->officialFields, $official->custom_fields, 'birth_place'),
                    null,
                ),
                'address' => $this->answer($form->officialFields, $official->custom_fields, 'address'),
                'photo' => $this->images->dataUri($official->photo_url, 'photo'),
            ])->all(),
        ];
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

    /**
     * "Tempat, d F Y" — whichever halves exist, joined only when both do.
     *
     * The paper form has one row for both, so an entry with a date but no
     * birthplace prints just the date rather than a stray comma.
     */
    protected function birthLine(string $place, $date): string
    {
        $date = $date ? Carbon::parse($date)->locale('id')->translatedFormat('d F Y') : '';

        return match (true) {
            $place !== '' && $date !== '' => $place.', '.$date,
            $place !== '' => $place,
            default => $date,
        };
    }

    /**
     * One custom-field answer, found by hint rather than by exact key.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>|null  $answers
     */
    protected function answer(array $fields, ?array $answers, string $hint): string
    {
        if (! $answers) {
            return '';
        }

        foreach ($fields as $field) {
            $haystack = Str::lower($field['key'].' '.$field['label']);

            foreach (self::FIELD_HINTS[$hint] as $needle) {
                if (str_contains($haystack, $needle)) {
                    $value = trim((string) ($answers[$field['key']] ?? ''));

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return '';
    }
}
