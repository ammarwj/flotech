<?php

namespace App\Support;

use App\Models\Event;

/**
 * The shape of one event's registration form, as the organizer defined it.
 *
 * Four lists, read from `events.registration_form`: extra fields on the team,
 * extra fields on each player, documents the team must upload, documents each
 * player must upload. An event that defines none of them gets a form that looks
 * exactly like it did before this feature existed — and, more importantly, one
 * that shows no upload UI at all rather than an empty box.
 *
 * The value object exists for the same reason DisciplineRules does: several
 * callers need to agree on the shape of a JSON blob, and a normalizer they all
 * go through is what stops them drifting. Everything here is read-only; the
 * writing side is EventController::syncRegistrationForm().
 */
final class RegistrationForm
{
    /** Field kinds an organizer may choose from. */
    public const TYPES = ['short_text', 'long_text', 'select', 'date'];

    /** File kinds a document slot may accept. */
    public const ACCEPTS = ['pdf', 'jpg', 'png'];

    /** The four lists, in the order the builder UI shows them. */
    public const SECTIONS = ['team_fields', 'player_fields', 'team_documents', 'player_documents'];

    /**
     * @param  list<array<string, mixed>>  $teamFields
     * @param  list<array<string, mixed>>  $playerFields
     * @param  list<array<string, mixed>>  $teamDocuments
     * @param  list<array<string, mixed>>  $playerDocuments
     */
    private function __construct(
        public readonly array $teamFields,
        public readonly array $playerFields,
        public readonly array $teamDocuments,
        public readonly array $playerDocuments,
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
            teamDocuments: self::cleanDocuments($raw['team_documents'] ?? []),
            playerDocuments: self::cleanDocuments($raw['player_documents'] ?? []),
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
            && $this->teamDocuments === []
            && $this->playerDocuments === [];
    }

    /**
     * The stored shape, for the API resources.
     *
     * Always all four keys, even when empty: the client renders each section
     * from its list, and a missing key would have to be defended against in
     * four places instead of none.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_fields' => $this->teamFields,
            'player_fields' => $this->playerFields,
            'team_documents' => $this->teamDocuments,
            'player_documents' => $this->playerDocuments,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsFor(string $section): array
    {
        return $section === 'player' ? $this->playerFields : $this->teamFields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documentsFor(string $section): array
    {
        return $section === 'player' ? $this->playerDocuments : $this->teamDocuments;
    }

    /**
     * Every document key this event knows about, both scopes.
     *
     * The two lists are checked separately when enforcing "did you upload the
     * required ones", but a document's *type* is validated against this: a team
     * uploading a slot defined for players is a client bug, not a security
     * question, and one list keeps that check to a single lookup.
     *
     * @return list<string>
     */
    public function documentKeys(): array
    {
        return [
            ...array_column($this->teamDocuments, 'key'),
            ...array_column($this->playerDocuments, 'key'),
        ];
    }

    /**
     * The document definition behind a key, or null when nothing defines it.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $key): ?array
    {
        foreach ([...$this->teamDocuments, ...$this->playerDocuments] as $doc) {
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

        foreach (['team_fields', 'player_fields'] as $section) {
            $rules[$section] = ['present', 'array', 'max:30'];
            $rules[$section.'.*.key'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'];
            $rules[$section.'.*.label'] = ['required', 'string', 'max:100'];
            $rules[$section.'.*.type'] = ['required', 'string', 'in:'.implode(',', self::TYPES)];
            $rules[$section.'.*.required'] = ['nullable', 'boolean'];
            $rules[$section.'.*.options'] = ['nullable', 'array', 'max:50'];
            $rules[$section.'.*.options.*'] = ['required', 'string', 'max:100'];
        }

        foreach (['team_documents', 'player_documents'] as $section) {
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
