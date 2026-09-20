<?php

namespace App\Imports;

use App\Models\Event;
use App\Models\EventCategory;
use App\Services\PlanGate;
use App\Services\TeamRosterService;
use App\Support\RegistrationTemplateColumns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Throwable;

/**
 * Bulk-creates teams from an uploaded template. The template has two sheets
 * ("Data" + "Panduan" — see RegistrationTemplateExportBundle); without
 * WithMultipleSheets naming just the one to read, ToCollection alone reads
 * every sheet in the file and tries to import the guide text as rows too.
 * Only "Data" is wired up here, so "Panduan" is never even parsed.
 */
class TeamRosterImport implements WithMultipleSheets
{
    private TeamRosterRowImport $rows;

    public function __construct(Event $event, EventCategory $category, TeamRosterService $roster, PlanGate $gate)
    {
        $this->rows = new TeamRosterRowImport($event, $category, $roster, $gate);
    }

    public function sheets(): array
    {
        return ['Data' => $this->rows];
    }

    public function created(): int
    {
        return $this->rows->created;
    }

    /** @return list<array{row: int, message: string}> */
    public function errors(): array
    {
        return $this->rows->errors;
    }
}

/**
 * One row = one team, through the exact same write path as a single manual
 * entry (RegistrationController::store): same offline-settlement fields,
 * same TeamRosterService, same category/plan quota checks. Reads columns
 * **by index** via RegistrationTemplateColumns — not WithHeadingRow — for
 * the same reason the export writes them that way.
 *
 * Each row runs in its own transaction so one bad row doesn't roll back
 * rows already saved. The quota is a running counter checked before each
 * row: once it's hit, the remaining rows are reported as one error and left
 * unprocessed rather than silently dropped.
 */
class TeamRosterRowImport implements ToCollection
{
    public int $created = 0;

    /** @var list<array{row: int, message: string}> */
    public array $errors = [];

    private int $teamCount;

    public function __construct(
        protected Event $event,
        protected EventCategory $category,
        protected TeamRosterService $roster,
        protected PlanGate $gate,
    ) {
        $this->teamCount = $category->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();
    }

    public function collection(SupportCollection $rows): void
    {
        $keys = RegistrationTemplateColumns::keys($this->category);
        $isFixed = $this->category->rosterSize() !== null;

        if (! $this->headerMatches($rows->first())) {
            $this->errors[] = [
                'row' => 1,
                'message' => 'Format template tidak sesuai kategori ini (kemungkinan file lama). Unduh ulang template lalu isi ulang tanpa mengubah kolomnya.',
            ];

            return;
        }

        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue; // header row
            }

            $excelRow = $i + 1;
            $values = $row->all();

            if ($this->isBlankRow($values)) {
                continue;
            }

            if ($this->category->max_teams !== null && $this->teamCount >= $this->category->max_teams) {
                $this->errors[] = [
                    'row' => $excelRow,
                    'message' => "Baris {$excelRow} dst. tidak diproses: kuota tim untuk kategori ini sudah penuh.",
                ];

                break;
            }

            if (! $this->gate->withinLimit($this->event, 'max_teams_per_category', $this->teamCount)) {
                $this->errors[] = [
                    'row' => $excelRow,
                    'message' => "Baris {$excelRow} dst. tidak diproses: batas jumlah entri per kategori untuk paket event ini sudah tercapai.",
                ];

                break;
            }

            $padded = array_pad(array_slice($values, 0, count($keys)), count($keys), null);
            $data = array_combine($keys, $padded);

            try {
                DB::transaction(fn () => $this->importRow($data, $isFixed));
                $this->teamCount++;
                $this->created++;
            } catch (ValidationException $e) {
                $this->errors[] = [
                    'row' => $excelRow,
                    'message' => "Baris {$excelRow}: ".implode(' ', $e->validator->errors()->all()),
                ];
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Gagal impor baris pendaftaran', [
                    'event_id' => $this->event->id,
                    'category_id' => $this->category->id,
                    'row' => $excelRow,
                    'error' => $e->getMessage(),
                ]);
                $this->errors[] = ['row' => $excelRow, 'message' => "Baris {$excelRow}: gagal disimpan."];
            }
        }
    }

    /**
     * The column layout is read by index, not by header name (see
     * RegistrationTemplateColumns), so a file whose columns don't match this
     * category's current template — an old download from before the column
     * count changed, or one with columns manually removed — would otherwise
     * get silently zipped against the wrong keys. That already happened: a
     * 16-column file (1 player slot before officials) fed through the
     * current 103-column team template put an official's name and role into
     * what the code read as player 2's name/jersey, corrupting the insert
     * instead of failing loudly. Comparing the header row up front turns
     * that into one clear, actionable error instead of a DB-level crash.
     */
    private function headerMatches(?SupportCollection $headerRow): bool
    {
        $expected = RegistrationTemplateColumns::headings($this->category);
        $actual = array_map(
            fn ($v) => trim((string) $v),
            $headerRow?->all() ?? [],
        );

        return array_slice($actual, 0, count($expected)) === $expected;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importRow(array $data, bool $isFixed): void
    {
        $rosterSize = $this->category->rosterSize();
        $players = [];

        if ($isFixed) {
            for ($p = 1; $p <= $rosterSize; $p++) {
                $name = trim((string) ($data["player_{$p}_name"] ?? ''));

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'players' => $rosterSize === 1
                            ? 'Kategori tunggal diisi tepat 1 pemain.'
                            : 'Kategori ganda diisi tepat 2 pemain.',
                    ]);
                }

                $players[] = [
                    'full_name' => $name,
                    'jersey_number' => null,
                    'position' => $this->nullableString($data["player_{$p}_position"] ?? null),
                ];
            }
        } else {
            for ($p = 1; $p <= RegistrationTemplateColumns::MAX_PLAYERS; $p++) {
                $name = trim((string) ($data["player_{$p}_name"] ?? ''));

                if ($name === '') {
                    continue;
                }

                $players[] = [
                    'full_name' => $name,
                    'jersey_number' => $this->nullableString($data["player_{$p}_jersey"] ?? null),
                    'position' => $this->nullableString($data["player_{$p}_position"] ?? null),
                ];
            }
        }

        $officials = [];

        if (! $isFixed) {
            for ($o = 1; $o <= RegistrationTemplateColumns::MAX_OFFICIALS; $o++) {
                $name = trim((string) ($data["official_{$o}_name"] ?? ''));

                if ($name === '') {
                    continue;
                }

                $officials[] = [
                    'full_name' => $name,
                    'role' => $this->nullableString($data["official_{$o}_role"] ?? null),
                ];
            }
        }

        $teamName = $isFixed
            ? implode(' / ', array_map(fn ($p) => $p['full_name'], $players))
            : trim((string) ($data['team_name'] ?? ''));

        if (! $isFixed && $teamName === '') {
            throw ValidationException::withMessages(['team_name' => 'Nama tim wajib diisi.']);
        }

        $team = $this->event->teams()->create([
            'category_id' => $this->category->id,
            'name' => $teamName,
            'logo_url' => null,
            'contact_name' => $this->nullableString($data['contact_name'] ?? null),
            'contact_phone' => $this->nullableString($data['contact_phone'] ?? null),
            'status' => 'approved',
            'registered_at' => Carbon::now(),
            'approved_at' => Carbon::now(),
            // Settled offline, same as a manual entry: nothing here belongs to
            // the platform, so nothing is credited to the wallet.
            'payment_status' => 'paid',
            'payment_amount' => 0,
            'platform_fee' => 0,
        ]);

        $this->roster->syncPlayers($team, $players);
        $this->roster->syncOfficials($team, $officials);
    }

    private function isBlankRow(array $values): bool
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
