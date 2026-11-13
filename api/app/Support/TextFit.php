<?php

namespace App\Support;

/**
 * How a value too long for its box is made to fit: one rule, two renderers.
 *
 * ID cards are drawn with GD and certificates with dompdf, so the two cannot
 * share a *measurement* — what they must share is the search, because the
 * number that comes out of it is a printed font size and two different answers
 * would mean a template previewed on one surface and wrong on the other.
 *
 * Stepping down by a factor rather than solving for the size, because the
 * relation is not monotone in any usable way: shrinking the glyphs can let one
 * more word onto a line, which *removes* a line, so the height does not fall
 * smoothly with the size. Twenty steps of 4% bottom out at ~44% of the
 * requested size, past which a name plate is unreadable anyway; the last step
 * is returned regardless rather than refusing to draw, because a slightly
 * overflowing name still beats a blank where a name should be.
 *
 * `web/lib/fit-text.ts` is the preview's mirror of this and uses the same
 * constants deliberately — a different step would make the canvas and the print
 * disagree by more than the font difference between them already does.
 */
final class TextFit
{
    /** Each step keeps 96% of the previous size. */
    public const FACTOR = 0.96;

    /** Floor of ~44% of the requested size. */
    public const STEPS = 20;

    /**
     * The largest size at or below `$size` whose rendered height is within
     * `$limit`.
     *
     * `$heightAt` is the caller's own measurement — Intervention's font
     * processor for the card, a glyph-width estimate for the PDF — because each
     * surface has to be measured by something that agrees with what draws it.
     *
     * A limit of zero or less means "no box", and the size is handed back
     * untouched: that is the state every template written before boxes existed
     * is in, and it must keep rendering exactly as it did.
     *
     * @param  callable(float): float  $heightAt  size → rendered block height
     */
    public static function shrink(float $size, float $limit, callable $heightAt): float
    {
        if ($limit <= 0 || $size <= 0) {
            return $size;
        }

        for ($i = 0; $i < self::STEPS; $i++) {
            if ($heightAt($size) <= $limit) {
                return $size;
            }

            $size *= self::FACTOR;
        }

        return $size;
    }

    /**
     * How many lines `$text` wraps to inside `$maxWidth`, measured against a
     * real TTF.
     *
     * For the PDF side, where the engine that lays the text out cannot be asked
     * in advance. It reimplements the greedy word-wrap every renderer uses —
     * fill a line until the next word would overflow — over `imageftbbox`,
     * which is why the font file matters: dompdf draws certificates in DejaVu
     * Sans and that exact TTF is committed to the repo, so the metrics here are
     * the metrics there.
     *
     * > **It is still an estimate, and that is stated rather than hidden.**
     * dompdf does its own line breaking, so a name can break one word earlier
     * or later than this predicts. The consequence of being off by a line is a
     * name one step larger or smaller than ideal — not a name outside its
     * plate, which is what happens with no fitting at all.
     *
     * A word longer than the whole width gets its own line rather than looping
     * forever; that is also what the greedy algorithm in
     * Intervention\Image\Drivers\AbstractFontProcessor::wrapLine() does.
     */
    public static function lineCount(string $text, string $fontPath, float $sizePt, float $maxWidth): int
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        if ($words === [] || $maxWidth <= 0) {
            return 1;
        }

        $width = function (string $s) use ($fontPath, $sizePt): float {
            $box = @imageftbbox($sizePt, 0, $fontPath, $s);

            // No font, or a glyph GD cannot measure: one line is the safe
            // answer, since the caller then simply does not shrink.
            return $box === false ? 0.0 : abs($box[4] - $box[6]);
        };

        $lines = 1;
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $width($candidate) > $maxWidth) {
                $lines++;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        return $lines;
    }
}
