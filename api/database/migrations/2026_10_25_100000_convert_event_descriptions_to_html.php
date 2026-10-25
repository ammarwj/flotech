<?php

use App\Support\HtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `events.description` is rich text now, so the plain text already in it has to
 * become HTML.
 *
 * The old column was rendered with `white-space: pre-line`, which is what gave
 * those `\n` their meaning. Shipping the rich-text page without this migration
 * would not fail anywhere: every existing description renders as ONE paragraph
 * with the line breaks collapsed, so a tournament's rules run together into a
 * wall of text and the organizer's only clue is that their page looks worse
 * than yesterday.
 *
 * Idempotent by construction: rows that already contain a block tag are skipped,
 * so a re-run is a no-op rather than a second round of escaping. That is also
 * what makes it safe to run after the feature has been live for a while.
 *
 * No `down()` worth writing. Going back means stripping tags, which cannot
 * restore the paragraphs it is asked to undo — and the page's pre-line rendering
 * is gone by then anyway, so the honest answer is that this is one-way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->select('id', 'description')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $html = $this->toHtml((string) $event->description);

                    if ($html === null) {
                        continue;
                    }

                    DB::table('events')->where('id', $event->id)->update([
                        'description' => $html,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Deliberately empty — see the class docblock.
    }

    /**
     * Plain text to paragraphs, or null when the row is already HTML.
     *
     * A blank line separates paragraphs and a single newline is a <br>, which
     * is the reading `white-space: pre-line` gave it and the one Tiptap will
     * now produce from the same keystrokes.
     */
    private function toHtml(string $text): ?string
    {
        // One block tag is enough to tell this row apart: the editor always
        // wraps its output, so anything it wrote has at least a <p>.
        if (preg_match('/<(p|h[1-6]|ul|ol|blockquote|pre|hr|img|div)\b/i', $text)) {
            return null;
        }

        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split('/\n{2,}/', trim($normalised)) ?: [];

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // Escaped, not passed through: a stray `<` or `&` typed into the old
            // textarea was literal text there, and the sanitiser downstream
            // would drop it rather than print it.
            $escaped = $this->linkify(e($paragraph));
            $html .= '<p>'.str_replace("\n", '<br>', $escaped).'</p>';
        }

        if ($html === '') {
            return null;
        }

        // Through the same sanitiser every other writer goes through, rather
        // than hand-writing target/rel onto the anchors above. Not belt and
        // braces: it is what keeps the invariant on Event::description() true
        // of rows this migration wrote, and it is the only reason linkify()
        // can stay ignorant of HTML.TargetBlank. A second copy of that rule
        // here would drift from HtmlSanitizer the first time it was tuned, and
        // the drift would be invisible — the links still work, they just hand
        // the opened tab a live window.opener back into flo-event.
        return HtmlSanitizer::clean($html);
    }

    /**
     * Bare URLs become links.
     *
     * Not cosmetic housekeeping: a pasted Drive link to the player rules was
     * the whole reason some organizers typed a URL into a textarea that could
     * not link it, and the new editor autolinks what is typed into it. Leaving
     * the old ones as dead text would make the same paragraph behave two ways
     * depending on when it was written.
     *
     * Runs on already-escaped text and emits no attributes of its own —
     * target/rel are HtmlSanitizer's job (HTML.TargetBlank), so this cannot
     * disagree with what the editor produces.
     */
    private function linkify(string $escaped): string
    {
        // Trailing punctuation is excluded from the match: a sentence ending in
        // "…/view." would otherwise carry the full stop into the href.
        return preg_replace_callback(
            '~\bhttps?://[^\s<]+[^\s<.,;:!?)\]]~i',
            fn (array $m) => '<a href="'.$m[0].'">'.$m[0].'</a>',
            $escaped,
        ) ?? $escaped;
    }
};
