<?php

namespace App\Support;

use App\Models\Event;

/**
 * The shape of one event's registration form, as the organizer defined it.
 *
 * Six lists, read from `events.registration_form`: extra fields on the team,
 * on each player, on each official, and documents the team, each player, and
 * each official must upload. An event that defines none of them gets a form
 * that looks exactly like it did before this feature existed — and, more
 * importantly, one that shows no upload UI at all rather than an empty box.
 *
 * The value object exists for the same reason DisciplineRules does: several
 * callers need to agree on the shape of a JSON blob, and a normalizer they all
 * go through is what stops them drifting. Everything here is read-only; the
 * writing side is EventController::syncRegistrationForm().
 *
 * A field also carries three independent visibility flags: `is_public`, which
 * decides whether the answers appear on the public event page, `in_album`,
 * which decides whether the field gets a row on the printed player album, and
 * `in_schedule`, which decides whether the answer is printed beside the entrant's
 * name on a fixture card. They are deliberately not one flag — the album is a
 * sheet panitia hand out at the venue, and a birthplace an organizer is happy to
 * print on it is not the same decision as publishing it on a URL anyone can
 * index; and a fixture card has room for a club name, not for a date of birth.
 *
 * Documents deliberately have neither flag:
 * they are uploaded files -- an ID card, a birth certificate -- and publishing
 * one is a different class of decision from publishing a line of text the
 * entrant typed. Adding it there would need its own thinking, not this one
 * copied across.
 *
 * Three further things a field may carry, all optional and all absent-is-off so
 * a schema saved before they existed keeps behaving exactly as it did:
 *
 * - `help_text` / `help_image_url`: guidance shown with the question. A jersey
 *   size chart is a picture, not a sentence, so the image is its own key rather
 *   than markup smuggled through the text — the forms render an `<img>` whose
 *   `alt` is the field's own label.
 * - `visible_if`: the field is only asked when another answer matches. Resolved
 *   by isVisible(), which is the ONLY reader of the rule: a hidden field must
 *   be neither demanded (a required question nobody can see locks the form) nor
 *   stored (a NIM left on somebody who ended up picking UMUM), and those two
 *   are separate call sites that would drift if each decided for itself.
 */
final class RegistrationForm
{
    /**
     * Field kinds an organizer may choose from.
     *
     * `consent` is a tickbox whose stored value is WHEN it was ticked, not that
     * it was — see normalizeAnswer(). A boolean would have needed a second
     * column to answer "when", and a second column is a second writer of the
     * same fact.
     */
    public const TYPES = ['short_text', 'long_text', 'select', 'date', 'consent'];

    /** File kinds a document slot may accept. */
    public const ACCEPTS = ['pdf', 'jpg', 'png'];

    /** The six lists, in the order the builder UI shows them. */
    public const SECTIONS = [
        'team_fields',
        'player_fields',
        'team_official_fields',
        'team_documents',
        'player_documents',
        'team_official_documents',
    ];

    /**
     * @param  list<array<string, mixed>>  $teamFields
     * @param  list<array<string, mixed>>  $playerFields
     * @param  list<array<string, mixed>>  $officialFields
     * @param  list<array<string, mixed>>  $teamDocuments
     * @param  list<array<string, mixed>>  $playerDocuments
     * @param  list<array<string, mixed>>  $officialDocuments
     */
    private function __construct(
        public readonly array $teamFields,
        public readonly array $playerFields,
        public readonly array $officialFields,
        public readonly array $teamDocuments,
        public readonly array $playerDocuments,
        public readonly array $officialDocuments,
    ) {}

    public static function forEvent(?Event $event): self
    {
        return self::fromArray($event?->registration_form ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        $raw ??= [];

        return new self(
            teamFields: self::cleanFields($raw['team_fields'] ?? []),
            playerFields: self::cleanFields($raw['player_fields'] ?? []),
            officialFields: self::cleanFields($raw['team_official_fields'] ?? []),
            teamDocuments: self::cleanDocuments($raw['team_documents'] ?? []),
            playerDocuments: self::cleanDocuments($raw['player_documents'] ?? []),
            officialDocuments: self::cleanDocuments($raw['team_official_documents'] ?? []),
        );
    }

    /**
     * True when nothing at all is defined. The registration forms ask this to
     * decide whether the feature exists for this event.
     */
    public function isEmpty(): bool
    {
        return $this->teamFields === []
            && $this->playerFields === []
            && $this->officialFields === []
            && $this->teamDocuments === []
            && $this->playerDocuments === []
            && $this->officialDocuments === [];
    }

    /**
     * The stored shape, for the API resources.
     *
     * Always all six keys, even when empty: the client renders each section
     * from its list, and a missing key would have to be defended against in
     * six places instead of none.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_fields' => $this->teamFields,
            'player_fields' => $this->playerFields,
            'team_official_fields' => $this->officialFields,
            'team_documents' => $this->teamDocuments,
            'player_documents' => $this->playerDocuments,
            'team_official_documents' => $this->officialDocuments,
        ];
    }

    /**
     * Whether this field is asked at all, given the answers so far.
     *
     * **The only reader of `visible_if`**, and that is the point. A hidden
     * field must be neither demanded nor stored, and those are two call sites
     * (answerErrors/assertFormComplete, and cleanAnswers) that each pass their
     * own one-sided test: demanding one locks the form on a question nobody can
     * see, storing one leaves a NIM on a runner who picked UMUM. One answer
     * cannot drift from itself.
     *
     * The trigger is matched on its TRIMMED STRING value, the same shape
     * answerErrors compares a select against — the stored answers are strings
     * either way, and comparing loosely would make "0" match an empty answer.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     */
    public static function isVisible(array $field, array $answers): bool
    {
        $rule = $field['visible_if'] ?? null;

        if (! is_array($rule)) {
            return true;
        }

        $value = $answers[$rule['field']] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : '';

        return in_array($value, $rule['values'], true);
    }

    /**
     * The visible subset of a section's fields, in order.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    public function visibleFields(string $section, array $answers): array
    {
        return array_values(array_filter(
            $this->fieldsFor($section),
            fn (array $field) => self::isVisible($field, $answers),
        ));
    }

    /**
     * What goes in `custom_fields` for one answer, as this field type stores it.
     *
     * **A `consent` field stores the INSTANT it was ticked, on the server's
     * clock.** The whole requirement is "recorded with its time", and a time the
     * submitting client supplies is one the person agreeing can choose — so
     * whatever arrives is discarded and `now()` is written in its place. That
     * also means the value is idempotent in the only way that matters: ticking
     * an already-ticked box on an edit re-stamps it, which is the honest answer
     * ("this is when they last affirmed it") rather than a frozen first tick
     * nobody can account for.
     *
     * Every other type stores the trimmed text it was given. Null is returned
     * for an answer that is not there, and the callers drop the key — an absent
     * key and an empty string must not both mean "unanswered".
     */
    public static function normalizeAnswer(string $type, mixed $value): ?string
    {
        if ($type === 'consent') {
            // Anything falsy is "not ticked". "0" and "false" are included
            // because an unchecked box is exactly what a client sends that way,
            // and reading them as consent would record agreement nobody gave.
            $ticked = is_scalar($value)
                && ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no'], true);

            return $ticked ? now()->toIso8601String() : null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsFor(string $section): array
    {
        return match ($section) {
            'player' => $this->playerFields,
            'official' => $this->officialFields,
            default => $this->teamFields,
        };
    }

    /**
     * The answers a squad gave, trimmed to the fields the organizer marked
     * public and paired with their labels, in the order the form asks them.
     *
     * Resolved here rather than by joining the schema on the client, and
     * returned as a list rather than a map, for the same reason the roster
     * itself is mapped field by field in PublicEventResource: a payload that
     * carries every answer and trusts the reader to hide some is one leak away
     * from a component that forgot to. What isn't published isn't sent.
     *
     * `section` is one of the words fieldsFor() understands: player, official,
     * or anything else for the team.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<array{key: string, label: string, value: string}>
     */
    public function publicAnswers(string $section, ?array $answers): array
    {
        $answers ??= [];
        $out = [];

        foreach ($this->fieldsFor($section) as $field) {
            if (! ($field['is_public'] ?? false)) {
                continue;
            }

            $value = $answers[$field['key']] ?? null;

            // An unanswered optional field is nothing to show. A blank row
            // under a label reads as missing data rather than as a question
            // this entrant simply didn't have to answer.
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $out[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'value' => self::displayValue($field, trim((string) $value)),
            ];
        }

        return $out;
    }

    /**
     * The rows the printed album should carry for one person, label and all.
     *
     * Unlike publicAnswers(), an unanswered field is **kept** — blank. The album
     * is a form panitia photocopy and finish by hand, so a labelled empty row is
     * the useful output there, while on a public page it would read as missing
     * data. Same schema, two readers, two rules; that is why this is its own
     * method rather than a flag on the other one.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<array{label: string, value: string}>
     */
    public function albumRows(string $section, ?array $answers): array
    {
        $answers ??= [];
        $out = [];

        foreach ($this->fieldsFor($section) as $field) {
            if (! ($field['in_album'] ?? false)) {
                continue;
            }

            $value = is_scalar($answers[$field['key']] ?? null)
                ? trim((string) $answers[$field['key']])
                : '';

            $out[] = [
                'label' => $field['label'],
                'value' => $field['type'] === 'date'
                    ? self::albumDate($value)
                    : self::displayValue($field, $value),
            ];
        }

        return $out;
    }

    /**
     * The answers one entrant's row contributes beside their name on a fixture
     * card — a club name under "PB. Uncrown Medan", a school, a community.
     *
     * **Values only, no labels, and blanks dropped.** That is the whole
     * difference from the other two readers and it is what the surface is:
     * a fixture card has one line of room under a name, and "Nama PB: PB.
     * Uncrown Medan" spends half of it saying what the reader can already see.
     * publicAnswers() keeps labels because a squad dialog is a list of
     * questions; albumRows() keeps blanks because a printed sheet is filled in
     * by hand. Neither is true here, which is why this is its own method rather
     * than a third flag on one of them.
     *
     * Deduplicated: a doubles pair whose two players both typed the same club
     * is one club, and printing it twice under one entry name is noise the
     * organizer has no control over.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<string>
     */
    public function scheduleTags(string $section, ?array $answers): array
    {
        $answers ??= [];
        $out = [];

        foreach ($this->fieldsFor($section) as $field) {
            if (! ($field['in_schedule'] ?? false)) {
                continue;
            }

            $value = $answers[$field['key']] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $out[] = self::displayValue($field, trim((string) $value));
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether any field in a section is printed on fixture cards at all.
     *
     * Asked before a query, not after: the rosters a fixture's tags are read
     * from are only worth eager loading for an event that opted in, and a
     * category of 158 fixtures would otherwise pull two squads per row for a
     * flag nobody ticked. See GameMatch::fixtureRelations(), the only caller.
     */
    public function hasScheduleTags(string $section): bool
    {
        foreach ($this->fieldsFor($section) as $field) {
            if ($field['in_schedule'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * One stored answer as a human reads it, for every surface that prints one.
     *
     * Only `consent` needs translating: it stores an instant, and "2026-10-17T
     * 09:14:02+07:00" on a public page or a printed sheet is a machine talking
     * to itself. Everything else is already what the entrant typed.
     *
     * Written once and shared by publicAnswers() and albumRows() because the
     * two differ on BLANKS, not on formatting — a second copy would be the
     * place where one surface starts printing raw ISO and nobody notices, since
     * each reader's own test would still pass.
     *
     * @param  array<string, mixed>  $field
     */
    public static function displayValue(array $field, string $value): string
    {
        if (($field['type'] ?? null) !== 'consent' || $value === '') {
            return $value;
        }

        try {
            // `wallet.timezone` is the venue clock this whole schema already
            // reads windows on (RacePeriod, TicketCategory, the hold release).
            // App timezone is UTC, so formatting naively would print a consent
            // given at 00:30 WIB as the previous day.
            return 'Ya · '.\Illuminate\Support\Carbon::parse($value)
                ->timezone(config('wallet.timezone', 'Asia/Jakarta'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            // A value that is not the instant this type promised: a schema
            // whose type was switched after answers came in. The raw text beats
            // a wrong date, the same call albumDate() makes.
            return $value;
        }
    }

    /**
     * "2003-09-26" as "26 Sept 2003", for the printed album only.
     *
     * The month names are spelled out here rather than taken from Carbon's `id`
     * locale, which abbreviates September to "Sep": the sheet is a form panitia
     * read at a glance, and three letters that also start "Sep-tember" in every
     * other row's handwriting is exactly the ambiguity the fourth letter
     * settles. Screens keep showing whatever the entrant typed — this is a
     * print concern, so it lives on the print path.
     *
     * Anything that is not a date the field's own type promised is returned
     * untouched: the flag can be ticked on a field whose type was changed after
     * answers came in, and a garbled "01 Jan 1970" would be worse than the raw
     * text the person actually wrote.
     */
    private static function albumDate(string $value): string
    {
        if ($value === '' || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return $value;
        }

        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sept', 'Okt', 'Nov', 'Des'];
        $month = $months[(int) $m[2] - 1] ?? null;

        return $month ? ((int) $m[3]).' '.$month.' '.$m[1] : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documentsFor(string $section): array
    {
        return match ($section) {
            'player' => $this->playerDocuments,
            'official' => $this->officialDocuments,
            default => $this->teamDocuments,
        };
    }

    /**
     * Every document key this event knows about, all three scopes.
     *
     * The lists are checked separately when enforcing "did you upload the
     * required ones", but a document's *type* is validated against this: a
     * team uploading a slot defined for players is a client bug, not a
     * security question, and one list keeps that check to a single lookup.
     *
     * @return list<string>
     */
    public function documentKeys(): array
    {
        return [
            ...array_column($this->teamDocuments, 'key'),
            ...array_column($this->playerDocuments, 'key'),
            ...array_column($this->officialDocuments, 'key'),
        ];
    }

    /**
     * The document definition behind a key, or null when nothing defines it.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $key): ?array
    {
        foreach ([...$this->teamDocuments, ...$this->playerDocuments, ...$this->officialDocuments] as $doc) {
            if ($doc['key'] === $key) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * Validation rules for a submitted schema, shared by every request that
     * accepts one so they cannot drift.
     *
     * The uniqueness of keys and the "options are mandatory for a select" rule
     * are not expressible here and live in the controller, which is also where
     * the in-use check has to happen anyway.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        $rules = [];

        foreach (['team_fields', 'player_fields', 'team_official_fields'] as $section) {
            $rules[$section] = ['present', 'array', 'max:30'];
            $rules[$section.'.*.key'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'];
            $rules[$section.'.*.label'] = ['required', 'string', 'max:100'];
            $rules[$section.'.*.type'] = ['required', 'string', 'in:'.implode(',', self::TYPES)];
            $rules[$section.'.*.required'] = ['nullable', 'boolean'];
            $rules[$section.'.*.is_public'] = ['nullable', 'boolean'];
            $rules[$section.'.*.in_album'] = ['nullable', 'boolean'];
            $rules[$section.'.*.in_schedule'] = ['nullable', 'boolean'];
            $rules[$section.'.*.options'] = ['nullable', 'array', 'max:50'];
            $rules[$section.'.*.options.*'] = ['required', 'string', 'max:100'];
            $rules[$section.'.*.help_text'] = ['nullable', 'string', 'max:500'];
            $rules[$section.'.*.help_image_url'] = ['nullable', 'string', 'url', 'max:2048'];
            // The rule's own shape only. That its `field` names a key defined
            // EARLIER IN THE SAME LIST is the part a flat rule array cannot
            // express, and it lives in SyncRegistrationFormRequest beside the
            // other two — see that class for why "earlier" rather than "anywhere".
            $rules[$section.'.*.visible_if'] = ['nullable', 'array'];
            $rules[$section.'.*.visible_if.field'] = ['required_with:'.$section.'.*.visible_if', 'string', 'max:50'];
            $rules[$section.'.*.visible_if.values'] = ['required_with:'.$section.'.*.visible_if', 'array', 'min:1', 'max:50'];
            $rules[$section.'.*.visible_if.values.*'] = ['required', 'string', 'max:100'];
        }

        foreach (['team_documents', 'player_documents', 'team_official_documents'] as $section) {
            $rules[$section] = ['present', 'array', 'max:20'];
            $rules[$section.'.*.key'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'];
            $rules[$section.'.*.label'] = ['required', 'string', 'max:100'];
            $rules[$section.'.*.required'] = ['nullable', 'boolean'];
            $rules[$section.'.*.accept'] = ['required', 'array', 'min:1'];
            $rules[$section.'.*.accept.*'] = ['required', 'string', 'in:'.implode(',', self::ACCEPTS)];
        }

        return $rules;
    }

    /**
     * Normalize a field list: keep only the keys we know, in a fixed shape.
     *
     * Rows are stored in the order they were typed — that is the order they are
     * shown, the same convention team_officials.sort_order encodes explicitly.
     * Here the JSON array carries it for free.
     *
     * @param  mixed  $rows
     * @return list<array<string, mixed>>
     */
    private static function cleanFields($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key'], $row['label'])) {
                continue;
            }

            $type = in_array($row['type'] ?? null, self::TYPES, true) ? $row['type'] : 'short_text';

            $clean[] = [
                'key' => (string) $row['key'],
                'label' => (string) $row['label'],
                'type' => $type,
                'required' => (bool) ($row['required'] ?? false),
                // Absent reads as private. The whole point of the flag is that
                // an organizer opts a field *in*, so a schema saved before it
                // existed -- or one sent by a client that has never heard of
                // it -- must not start publishing answers people gave to a
                // form that promised nothing of the sort.
                'is_public' => (bool) ($row['is_public'] ?? false),
                // Absent reads as "not printed", same reasoning as is_public:
                // a schema saved before this flag existed must not start
                // growing rows on a sheet the organizer already designed.
                'in_album' => (bool) ($row['in_album'] ?? false),
                // Absent reads as "not on the card", the same reasoning again:
                // a schema saved before this flag existed must not start
                // printing answers under names on a schedule the organizer and
                // every visitor already read.
                'in_schedule' => (bool) ($row['in_schedule'] ?? false),
                // Options only mean anything for a select; carrying them on the
                // other types would let a stale list reappear if the organizer
                // switched the type back.
                'options' => $type === 'select'
                    ? array_values(array_map('strval', array_filter(
                        is_array($row['options'] ?? null) ? $row['options'] : [],
                        fn ($o) => is_scalar($o) && trim((string) $o) !== '',
                    )))
                    : [],
                // Guidance beside the question. Null rather than '' so the
                // forms can render nothing at all instead of an empty line --
                // same reason an event with no documents shows no upload UI.
                'help_text' => self::cleanText($row['help_text'] ?? null),
                'help_image_url' => self::cleanText($row['help_image_url'] ?? null),
                'visible_if' => self::cleanCondition($row['visible_if'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * A trimmed string, or null when there is nothing there.
     *
     * Null and '' must not both be storable: the forms branch on "is there
     * guidance", and two spellings of "no" is two branches that will disagree.
     */
    private static function cleanText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The `visible_if` rule, or null when the field is always asked.
     *
     * A rule naming no values can never match, which would hide the field
     * forever — so it normalizes to null (always visible) rather than to a
     * question nobody is ever asked. Same reasoning as a document slot that
     * accepts nothing falling back to every kind.
     *
     * @return array{field: string, values: list<string>}|null
     */
    private static function cleanCondition(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $field = self::cleanText($raw['field'] ?? null);

        if ($field === null) {
            return null;
        }

        $values = array_values(array_unique(array_map(
            'strval',
            array_filter(
                is_array($raw['values'] ?? null) ? $raw['values'] : [],
                fn ($v) => is_scalar($v) && trim((string) $v) !== '',
            ),
        )));

        return $values === [] ? null : ['field' => $field, 'values' => $values];
    }

    /**
     * @param  mixed  $rows
     * @return list<array<string, mixed>>
     */
    private static function cleanDocuments($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key'], $row['label'])) {
                continue;
            }

            $accept = array_values(array_intersect(
                is_array($row['accept'] ?? null) ? $row['accept'] : [],
                self::ACCEPTS,
            ));

            $clean[] = [
                'key' => (string) $row['key'],
                'label' => (string) $row['label'],
                'required' => (bool) ($row['required'] ?? false),
                // A slot that accepts nothing could never be filled, and a
                // required one would deadlock the form. Fall back to everything.
                'accept' => $accept !== [] ? $accept : self::ACCEPTS,
            ];
        }

        return $clean;
    }
}
