<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Organization;
use App\Services\Catalog;

/**
 * Which fields an ID card or certificate template may place, and what each one
 * prints.
 *
 * Two halves used to live apart and neither could see the other: the placeable
 * list was a flat constant in config/id_card.php and config/certificate.php,
 * while everything the entrant actually typed sat in `custom_fields` behind
 * RegistrationForm. So a template could print a name and a team and nothing
 * else — an organizer who asked every player for a jersey size, a school, a
 * community could not put one of those answers on the card they were collected
 * for. This class is the join, and it is the ONLY reader of that rule: the
 * editor's picker, the save-time validator and the renderer all come through
 * here, so a key offered by one is a key the other two agree exists.
 *
 * > **Invarian: katalog field diturunkan dari EVENT, template milik ORG.**
 * Templates are org-scoped rows (`id_card_templates.organization_id`, no
 * `event_id`) while a custom field belongs to one event's `registration_form`,
 * and that asymmetry is deliberate on both sides: a lanyard design is reused
 * season after season, a form question is not. The consequence is that a
 * template may legitimately carry a key the event being printed never asked —
 * and that prints BLANK, exactly as an unanswered optional field already does.
 * What must never happen is a key no event ever asked, which is the plan_features
 * failure in another costume: `fields.*.key` is a plain string, so a typo saves
 * green and then prints nothing on five hundred cards with nobody to tell. That
 * is what knownKeys() refuses at save time.
 *
 * Custom keys are namespaced `custom:{section}:{key}` because the three lists
 * are three namespaces: RegistrationForm enforces key uniqueness *per list*, so
 * a team question and a player question may both be called `asal_sekolah` and
 * mean different things. Flattening them would make one silently shadow the
 * other, and which one won would depend on array order.
 */
final class TemplateFields
{
    public const CUSTOM_PREFIX = 'custom:';

    /**
     * The three answer lists, in the order the picker groups them, with the
     * heading each one gets. Keys match RegistrationForm::fieldsFor()'s words,
     * deliberately — a second spelling here would be a second mapping to keep
     * aligned with that method.
     */
    public const SECTIONS = [
        'team' => 'Formulir tim',
        'player' => 'Formulir pemain',
        'official' => 'Formulir ofisial',
    ];

    /** Heading the fixed catalogue sits under. */
    public const STANDARD_GROUP = 'Data standar';

    /**
     * Which answer lists feed one ID card recipient pool.
     *
     * `personnel` is empty and that is not an oversight: referees and staff are
     * rows an organizer typed on the personnel screen, not entrants who filled
     * in a registration form, so there is no `custom_fields` on them to read.
     *
     * @var array<string, list<string>>
     */
    public const ID_CARD_SECTIONS = [
        'player' => ['team', 'player'],
        'official' => ['team', 'official'],
        'personnel' => [],
    ];

    /**
     * Which answer lists feed one certificate recipient.
     *
     * No `official`: CertificateService::recipients() hands out teams and
     * players only.
     *
     * @var array<string, list<string>>
     */
    public const CERTIFICATE_SECTIONS = [
        'player' => ['team', 'player'],
        'team' => ['team'],
    ];

    /**
     * Every field an ID card template may place, grouped for the picker.
     *
     * `$event` narrows the custom half to one event's form — that is what the
     * editor's event dropdown selects. Null means "the fixed catalogue only",
     * which is what a template being edited without an event chosen shows.
     *
     * @return list<array{key: string, label: string, group: string}>
     */
    public static function idCard(?Event $event): array
    {
        return [
            ...self::standard('id_card'),
            ...($event ? self::custom($event, array_keys(self::SECTIONS)) : []),
        ];
    }

    /**
     * Every field a certificate template may place, grouped for the picker.
     *
     * The official list is offered here too even though no certificate goes to
     * an official today: the key space is one, and a template that outlives
     * that limitation is better than a picker that has to be widened later.
     *
     * @return list<array{key: string, label: string, group: string}>
     */
    public static function certificate(?Event $event): array
    {
        return [
            ...self::standard('certificate'),
            ...($event ? self::custom($event, array_keys(self::SECTIONS)) : []),
        ];
    }

    /**
     * The fixed catalogue for one surface, still read out of its config file.
     *
     * @return list<array{key: string, label: string, group: string}>
     */
    public static function standard(string $surface): array
    {
        $out = [];

        foreach ((array) config("{$surface}.fields") as $key => $label) {
            $out[] = ['key' => (string) $key, 'label' => (string) $label, 'group' => self::STANDARD_GROUP];
        }

        return $out;
    }

    /**
     * One event's registration questions, as placeable fields.
     *
     * Documents are deliberately absent. They are uploaded files — a KTP, a
     * birth certificate — and drawing one onto a card is a different job from
     * printing a line of text: the renderer would have to fetch the object,
     * refuse the PDFs among them, and decide what a missing file looks like on
     * plastic. That is its own feature, not this one widened.
     *
     * @param  list<string>  $sections
     * @return list<array{key: string, label: string, group: string}>
     */
    public static function custom(Event $event, array $sections): array
    {
        $form = $event->registrationForm();
        $out = [];

        foreach ($sections as $section) {
            foreach ($form->fieldsFor($section) as $field) {
                $key = (string) ($field['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                $out[] = [
                    'key' => self::customKey($section, $key),
                    'label' => (string) ($field['label'] ?? $key),
                    'group' => self::SECTIONS[$section] ?? $section,
                ];
            }
        }

        return $out;
    }

    /**
     * Every key a template of this organization is allowed to carry: the fixed
     * catalogue plus the union of every custom question across every event it
     * owns.
     *
     * The union rather than one event's list, because a template is org-scoped
     * and reused — narrowing it to the event open in the editor would make
     * saving a template that already prints last season's school name fail the
     * moment a different event is selected. What it still refuses is a key no
     * form anywhere asked, which is the only shape of this mistake that can
     * never print anything.
     *
     * One query, and the JSON is decoded here rather than hydrating N Event
     * models: an organization with years of events behind it would otherwise
     * build a model per row to read one column.
     *
     * @return list<string>
     */
    public static function knownKeys(Organization $org, string $surface): array
    {
        $keys = array_column(self::standard($surface), 'key');

        $forms = Event::query()
            ->where('organization_id', $org->id)
            ->pluck('registration_form');

        foreach ($forms as $raw) {
            $form = RegistrationForm::fromArray(is_array($raw) ? $raw : []);

            foreach (array_keys(self::SECTIONS) as $section) {
                foreach ($form->fieldsFor($section) as $field) {
                    $key = (string) ($field['key'] ?? '');

                    if ($key !== '') {
                        $keys[] = self::customKey($section, $key);
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** The namespaced key one registration question is placed under. */
    public static function customKey(string $section, string $key): string
    {
        return self::CUSTOM_PREFIX.$section.':'.$key;
    }

    /**
     * Split a placed key back into its list and question, or null when it is a
     * fixed-catalogue key.
     *
     * @return array{section: string, key: string}|null
     */
    public static function parse(string $key): ?array
    {
        if (! str_starts_with($key, self::CUSTOM_PREFIX)) {
            return null;
        }

        $rest = substr($key, strlen(self::CUSTOM_PREFIX));
        $at = strpos($rest, ':');

        if ($at === false || $at === 0 || $at === strlen($rest) - 1) {
            return null;
        }

        return ['section' => substr($rest, 0, $at), 'key' => substr($rest, $at + 1)];
    }

    /**
     * What each `custom:` field prints for one subject, keyed exactly as a
     * template places it.
     *
     * Answers go through RegistrationForm::displayValue(), the same formatter
     * the public page, the printed album and the fixture cards use — a fourth
     * copy is where one surface starts printing a raw ISO consent timestamp
     * while its own test stays green.
     *
     * A section whose answers are absent contributes nothing rather than blank
     * keys: the renderer already draws nothing for a key it has no value for,
     * and an empty string here would be a second way to say the same thing.
     *
     * @param  array<string, array<string, mixed>|null>  $answers  section => the row's custom_fields
     * @return array<string, string>
     */
    public static function values(Event $event, array $answers): array
    {
        $form = $event->registrationForm();
        $out = [];

        foreach ($answers as $section => $given) {
            if (! is_array($given)) {
                continue;
            }

            foreach ($form->fieldsFor((string) $section) as $field) {
                $key = (string) ($field['key'] ?? '');
                $value = $given[$key] ?? null;

                if ($key === '' || ! is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }

                $out[self::customKey((string) $section, $key)] =
                    RegistrationForm::displayValue($field, trim((string) $value));
            }
        }

        return $out;
    }

    /**
     * A sport position key as a human reads it, or the raw key when the sport
     * has no master list for it.
     *
     * Here rather than in each service because both surfaces print the same
     * column and Catalog hands back null for an unmapped key — printed as-is,
     * `"libero"` beats an empty slot on a card someone is about to wear.
     */
    public static function positionLabel(?string $sport, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        return Catalog::positionLabel($sport, $key) ?? $key;
    }
}
