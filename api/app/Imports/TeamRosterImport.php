<?php

namespace App\Imports;

use App\Models\Event;
use App\Models\EventCategory;
use App\Services\PlanGate;
use App\Services\TeamRosterService;
use App\Support\RegistrationForm;
use App\Support\RegistrationTemplateColumns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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

    private RegistrationForm $form;

    public function __construct(
        protected Event $event,
        protected EventCategory $category,
        protected TeamRosterService $roster,
        protected PlanGate $gate,
    ) {
        $this->teamCount = $category->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();
        $this->form = RegistrationForm::forEvent($event);
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
     *
     * Compared case- and spacing-insensitively. Three of these headers are now
     * whatever the organizer renamed them to (RegistrationTemplateColumns reads
     * the event's labels), so the strictness that used to be free now costs: a
     * file filled in before a rename, or one Excel helpfully re-cased, would be
     * refused for a difference that cannot change what column N means. What the
     * check is actually for — a file whose COLUMN COUNT or ORDER no longer
     * matches — survives untouched.
     */
    private function headerMatches(?SupportCollection $headerRow): bool
    {
        $normalize = fn ($v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $v)) ?? '');

        $expected = array_map($normalize, RegistrationTemplateColumns::headings($this->category));
        $actual = array_map($normalize, $headerRow?->all() ?? []);

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
                    '_slot' => $p,
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
                    '_slot' => $p,
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
                    '_slot' => $o,
                ];
            }
        }

        // custom_fields rides along with the row it belongs to — a player's
        // answers go in $players[$p]['custom_fields'], not a separate pass,
        // because TeamRosterService::syncPlayers() only writes/validates
        // answers for a row that carries the key at all (array_key_exists).
        foreach ($players as $p => &$player) {
            $player['custom_fields'] = $this->customFieldAnswers($this->form->playerFields, $data, "cf_player_{$player['_slot']}_");
            unset($player['_slot']);
        }
        unset($player);

        foreach ($officials as $o => &$official) {
            $official['custom_fields'] = $this->customFieldAnswers($this->form->officialFields, $data, "cf_official_{$official['_slot']}_");
            unset($official['_slot']);
        }
        unset($official);

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

        // Same TeamRosterService::answerErrors()/cleanAnswers() pipeline a
        // manual entry goes through — required/select validation and
        // visible_if handling happen there, not here, so there is exactly one
        // place that can drift from RegistrationForm's rules.
        $this->roster->applyCustomFields($team, $this->customFieldAnswers($this->form->teamFields, $data, 'cf_team_'));
        $this->roster->syncPlayers($team, $players);
        $this->roster->syncOfficials($team, $officials);
    }

    /**
     * Pull one entity's custom-field cells out of a row, keyed by field key —
     * the shape TeamRosterService::cleanAnswers()/answerErrors() expect, the
     * same `{key: value}` map RegisterTeamRequest carries in from the public
     * form. `select` and `date` get normalized here because the raw cell
     * value an organizer can type or Excel can produce ("Ya"/"TRUE" for a
     * tickbox, an Excel date serial, an option typed in different case) is
     * not yet the string RegistrationForm::normalizeAnswer() expects, and
     * normalizeAnswer() itself must stay strict — it also runs on answers
     * coming from a browser input that already sends the right shape.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function customFieldAnswers(array $fields, array $data, string $prefix): array
    {
        $answers = [];

        foreach ($fields as $field) {
            $raw = $data["{$prefix}{$field['key']}"] ?? null;

            $answers[$field['key']] = match ($field['type']) {
                'select' => $this->matchOption($raw, $field['options']),
                'date' => $this->excelDate($raw),
                'consent' => $this->consentValue($raw),
                default => $raw,
            };
        }

        return $answers;
    }

    /**
     * An organizer typing into a spreadsheet will not reliably match an
     * option's exact case ("umum" for "Umum"), so this matches loosely and
     * returns the option's own spelling — the value
     * TeamRosterService::answerErrors() checks membership against with
     * `in_array(..., true)`, which is strict on purpose there because a select
     * coming from the public form is already constrained to the option list
     * by the UI. A value that matches nothing is passed through unchanged, so
     * answerErrors() reports it as "Pilihan tidak dikenali" instead of this
     * method silently discarding a typo into a blank.
     *
     * @param  list<string>  $options
     */
    private function matchOption(mixed $raw, array $options): mixed
    {
        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return $raw;
        }

        $needle = mb_strtolower(trim((string) $raw));

        foreach ($options as $option) {
            if (mb_strtolower($option) === $needle) {
                return $option;
            }
        }

        return $raw;
    }

    /**
     * A date cell comes back from maatwebsite/excel as a PHP float (the Excel
     * serial number) when the cell carries a date number format, or as plain
     * text otherwise — see Cell::getValue(), which is read here with
     * formatData left at its default `false`. Both are normalized to
     * `Y-m-d`, the format DatePicker/RegistrationForm already use everywhere
     * else, so a date typed in a spreadsheet lands the same way one typed
     * into the public form does. Text already in that shape, or anything
     * that is not a recognizable date, passes through untouched — the field
     * is not required to be a date; it only renders as one.
     */
    private function excelDate(mixed $raw): mixed
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        // A date-formatted cell comes back as the raw Excel serial number
        // (days since 1899-12-30) rather than a DateTime — maatwebsite/excel
        // uses the stock DefaultValueBinder, which does not convert it, and
        // this read path leaves $formatData at its default `false` so a date
        // cell isn't turned into a locale-formatted string either. A plain
        // 4-digit year typed as text ("2024") is numeric too but far too
        // small to be a real Excel date serial (day 2024 is in 1905), so the
        // threshold below keeps it from being misread as one.
        if (is_numeric($raw) && (float) $raw > 10000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return $raw;
            }
        }

        return $raw;
    }

    /**
     * A tickbox typed into a spreadsheet arrives as whatever the organizer
     * wrote — "Ya", "Yes", "TRUE", "1" — not the `CONSENT_ON` sentinel the
     * public form's checkbox sends. Normalized here to that same sentinel so
     * RegistrationForm::normalizeAnswer() — which only recognizes falsy
     * strings as "not ticked" and reads everything else as ticked — sees the
     * same shape regardless of source. Blank/"tidak"/"no"/"0" read as not
     * ticked; anything else (including a typo) reads as ticked, the same
     * "anything truthy is a tick" rule normalizeAnswer() itself applies.
     */
    private function consentValue(mixed $raw): mixed
    {
        if (! is_scalar($raw)) {
            return $raw;
        }

        $value = mb_strtolower(trim((string) $raw));

        return in_array($value, ['', '0', 'tidak', 'no', 'false'], true) ? '' : '1';
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
