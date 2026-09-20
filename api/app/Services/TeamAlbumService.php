<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Throwable;

/**
 * Renders the printable "player album" — one page per team, a photo grid of
 * every player plus a bench section for officials/coaches.
 *
 * Synchronous like ExportController, not a queued job like IdCardService:
 * a handful of teams' worth of photos renders fast enough inline, and there
 * is no batch/zip step to justify a background worker.
 */
class TeamAlbumService
{
    public function __construct(protected R2StorageService $r2) {}

    /**
     * The dompdf builder for one or more teams' album. Each team after the
     * first starts on a fresh page — see pdf/team-album.blade.php. Returns
     * the builder (not ->output() bytes) so the controller can call
     * ->download($name), which handles the Content-Disposition header
     * itself — see BillingDocumentService::pdf() for the same shape.
     */
    public function build(Event $event, Collection $teams): DomPdf
    {
        return Pdf::loadView('pdf.team-album', [
            'event' => $event,
            'teams' => $teams->map(fn (Team $team) => $this->teamPayload($event, $team)),
        ])->setPaper('a4', 'portrait');
    }

    /**
     * @return array<string, mixed>
     */
    protected function teamPayload(Event $event, Team $team): array
    {
        $players = $team->players
            ->sortBy(fn ($p) => $p->jersey_number ?? PHP_INT_MAX)
            ->values();

        return [
            'name' => $team->name,
            'logo' => $this->photoDataUri($team->logo_url),
            'category' => $team->category?->name,
            'players' => $players->map(fn ($player) => [
                'name' => $player->full_name,
                'jersey_number' => $player->jersey_number,
                'position' => Catalog::positionLabel($event->sport_type, $player->position),
                'date_of_birth' => $player->date_of_birth
                    ? Carbon::parse($player->date_of_birth)->translatedFormat('d F Y')
                    : null,
                'photo' => $this->photoDataUri($player->photo_url),
                'initials' => $this->initials($player->full_name),
            ])->all(),
            'officials' => $team->officials->map(fn ($official) => [
                'name' => $official->full_name,
                'role' => Catalog::officialRoleLabel($event->sport_type, $official->role) ?? 'Ofisial',
                'photo' => $this->photoDataUri($official->photo_url),
                'initials' => $this->initials($official->full_name),
            ])->all(),
        ];
    }

    /** First letters of up to two words, for the placeholder box when there is no photo. */
    protected function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(fn ($p) => mb_substr($p, 0, 1), array_filter($parts));

        return mb_strtoupper(implode('', array_slice($letters, 0, 2))) ?: '?';
    }

    /**
     * A stored photo inlined as a small JPEG data-URI, or null when there is
     * none / it can't be fetched. Re-encoded (not passed through) for two
     * reasons, both copied from CertificateService::backgroundDataUri(): our
     * uploads land as WebP and dompdf cannot draw WebP, and inlining spares
     * dompdf a remote fetch per photo.
     */
    protected function photoDataUri(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $bytes = $this->fetchBytes($url);

        if ($bytes === null) {
            return null;
        }

        try {
            $jpeg = (string) (new ImageManager(new GdDriver))
                ->decodeBinary($bytes)
                ->scaleDown(width: 400, height: 400)
                ->encode(new JpegEncoder(quality: 82));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    /** Copied from CertificateService::fetchBytes() — see that docblock. */
    protected function fetchBytes(string $url): ?string
    {
        $publicBase = rtrim((string) config('r2.public_url'), '/');

        try {
            if ($publicBase !== '' && str_starts_with($url, $publicBase.'/')) {
                return $this->r2->disk()->get(ltrim(Str::after($url, $publicBase), '/'));
            }

            $response = Http::timeout(15)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
