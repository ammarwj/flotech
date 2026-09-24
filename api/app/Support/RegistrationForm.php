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
 * A field also carries `is_public`, which decides whether the answers to it
 * appear on the public event page. Documents deliberately have no such flag:
 * they are uploaded files -- an ID card, a birth certificate -- and publishing
 * one is a different class of decision from publishing a line of text the
 * entrant typed. Adding it there would need its own thinking, not this one
 * copied across.
 */
final class RegistrationForm
{
    /** Field kinds an organizer may choose from. */
    public const TYPES = ['short_text', 'long_text', 'select', 'date'];

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
                'value' => trim((string) $value),
            ];
        }

        return $out;
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
            $rules[$section.'.*.options'] = ['nullable', 'array', 'max:50'];
            $rules[$section.'.*.options.*'] = ['required', 'string', 'max:100'];
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
                // Options only mean anything for a select; carrying them on the
                // other types would let a stale list reappear if the organizer
                // switched the type back.
                'options' => $type === 'select'
                    ? array_values(array_map('strval', array_filter(
                        is_array($row['options'] ?? null) ? $row['options'] : [],
                        fn ($o) => is_scalar($o) && trim((string) $o) !== '',
                    )))
                    : [],
            ];
        }

        return $clean;
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
