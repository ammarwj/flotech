<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * `2026_10_25_100000_convert_event_descriptions_to_html` — the plain text that
 * `events.description` held before it became rich text.
 *
 * Every row is written with DB::table(), not with the model: the mutator would
 * sanitise the fixture into exactly the shape the migration is supposed to
 * produce, and the test would pass against a migration that did nothing at all.
 */
class EventDescriptionHtmlMigrationTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function eventWithRawDescription(?string $description): Event
    {
        $event = $this->eventOn($this->orgFor(User::factory()->create()));

        DB::table('events')->where('id', $event->id)->update(['description' => $description]);

        return $event;
    }

    /**
     * Runs the migration's up() the way `migrate` would.
     *
     * RefreshDatabase has already run every migration against the empty table,
     * so this is the only way to see it work on rows — and requiring the file
     * returns the anonymous class it declares.
     */
    private function migrate(): void
    {
        (require __DIR__.'/../../database/migrations/2026_10_25_100000_convert_event_descriptions_to_html.php')->up();
    }

    /**
     * The whole point of the migration, and the pair is the test: a blank line
     * is a paragraph break, a single newline is a <br>. Asserting that <p>
     * appears at all would pass just as well if the entire description were
     * wrapped in one paragraph — which is precisely the broken output this
     * exists to prevent.
     */
    public function test_blank_lines_become_paragraphs_and_single_newlines_become_breaks(): void
    {
        $event = $this->eventWithRawDescription(
            "Turnamen mini soccer.\n\nATURAN PEMAIN :\nMaksimal 12 pemain."
        );

        $this->migrate();

        $this->assertSame(
            '<p>Turnamen mini soccer.</p><p>ATURAN PEMAIN :<br>Maksimal 12 pemain.</p>',
            (string) $event->fresh()->description,
        );
    }

    /**
     * A pasted Drive link to the player rules is why organizers typed URLs into
     * a textarea that could not link them, and the new editor autolinks what is
     * typed into it. Left as dead text, the same paragraph would behave two ways
     * depending on when it was written.
     *
     * The trailing full stop is the half that is easy to get wrong: carried into
     * the href it produces a link that 404s, which looks like the organizer's
     * mistake rather than ours.
     */
    public function test_bare_urls_become_links_without_swallowing_trailing_punctuation(): void
    {
        $event = $this->eventWithRawDescription(
            'Aturan: https://drive.google.com/file/d/abc/view?usp=drivesdk.'
        );

        $this->migrate();

        $html = (string) $event->fresh()->description;

        $this->assertStringContainsString('href="https://drive.google.com/file/d/abc/view?usp=drivesdk"', $html);
        // Outside the anchor, where it belongs.
        $this->assertStringEndsWith('</a>.</p>', $html);
    }

    /**
     * The rows this migration writes have to satisfy the same invariant as the
     * rows the mutator writes, because the page cannot tell them apart.
     *
     * A bare target="_blank" hands the opened tab a live `window.opener` back
     * into flo-event — and these anchors are built by linkify(), which is
     * deliberately ignorant of that rule. Passing the result through
     * HtmlSanitizer is what supplies it; hand-writing the attributes in the
     * migration would be a second copy of HTML.TargetBlank that drifts the
     * first time the real one is tuned, invisibly, since the links keep working
     * either way.
     */
    public function test_links_the_migration_creates_get_the_same_target_and_rel_as_the_editors(): void
    {
        $event = $this->eventWithRawDescription('Aturan: https://contoh.test/aturan.pdf');

        $this->migrate();

        $html = (string) $event->fresh()->description;

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('noopener', $html);
    }

    /**
     * `<` typed into the old textarea was literal text there — it rendered as a
     * less-than sign. Passed through unescaped it becomes markup the sanitiser
     * then drops, so the organizer's sentence silently loses a word.
     */
    public function test_characters_that_were_literal_in_the_textarea_stay_literal(): void
    {
        $event = $this->eventWithRawDescription('Usia < 17 tahun & sudah terdaftar');

        $this->migrate();

        $html = (string) $event->fresh()->description;

        $this->assertStringContainsString('&lt; 17', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    /**
     * Idempotent, which is what makes it safe to re-run after the feature has
     * been live: by then most rows are editor output, and a second pass over
     * them would escape their own tags into visible `&lt;p&gt;`.
     *
     * Compared before/after rather than just asserted non-empty — escaping
     * damage leaves a perfectly non-empty string.
     */
    public function test_rows_that_are_already_html_are_left_alone(): void
    {
        $event = $this->eventWithRawDescription('<p>Sudah rapi</p><p>Dua paragraf</p>');

        $this->migrate();
        $once = (string) $event->fresh()->description;

        $this->migrate();

        $this->assertSame('<p>Sudah rapi</p><p>Dua paragraf</p>', $once);
        $this->assertSame($once, (string) $event->fresh()->description);
    }

    /** Nothing to convert, and nothing invented either. */
    public function test_an_empty_description_is_left_null(): void
    {
        $event = $this->eventWithRawDescription(null);

        $this->migrate();

        $this->assertNull($event->fresh()->description);
    }
}
