"use client";

/**
 * The preview's half of shrink-to-fit.
 *
 * Mirror of `IdCardService::fittedSize()`: the largest size at or below the one
 * the organizer set whose wrapped text still fits the field's box, found by the
 * same twenty 4% steps. Written as a mirror rather than shared, because there
 * is nothing to share — one side measures with GD's `imageftbbox` over a TTF on
 * disk, the other with the browser's own layout.
 *
 * > **Caveat, stated rather than hidden: the two measure different fonts.** The
 * > renderer draws DejaVu Sans (the only TTF committed to the repo — GD cannot
 * > synthesise one), while the canvas shows the app's own display face. Glyph
 * > widths differ, so a name can break one word earlier on screen than on
 * > paper. That gap predates this function — the existing preview already wraps
 * > through CSS at the same percentage — and it is why the panel prints the
 * > fitted millimetre figure: a number an organizer can check with a ruler
 * > against a test print beats a preview that claims to be exact.
 *
 * Measured off-DOM in one reused element rather than on the real field: reading
 * the live node's height while changing its font size is a layout write inside
 * a read, and twenty of them per field per keystroke is the shape of a janky
 * editor. The element is created lazily and never removed — one hidden div for
 * the page's lifetime, which is cheaper than appending and discarding one per
 * measurement.
 */

let ruler: HTMLDivElement | null = null;

function measuringElement(): HTMLDivElement {
  if (ruler) return ruler;

  ruler = document.createElement("div");

  Object.assign(ruler.style, {
    position: "absolute",
    // Off-screen rather than `display: none`: a hidden element has no layout,
    // so it would measure zero at every size.
    left: "-9999px",
    top: "0",
    visibility: "hidden",
    whiteSpace: "normal",
    // Matches the renderer's FontFactory::lineHeight(1.2).
    lineHeight: "1.2",
    padding: "0",
    margin: "0",
    border: "0",
  });

  document.body.appendChild(ruler);

  return ruler;
}

export type FitOptions = {
  /** The size the organizer set, in canvas pixels. */
  basePx: number;
  /** Wrap width in canvas pixels. */
  maxWidthPx: number;
  /**
   * Box height in canvas pixels. Zero or less means the field has no box, and
   * nothing is shrunk — the same branch the renderer takes.
   */
  maxHeightPx: number;
  bold?: boolean;
  fontFamily?: string;
};

/**
 * The size this text should actually be drawn at, in canvas pixels.
 *
 * Returns `basePx` unchanged when there is no box, when the text is empty, or
 * when it already fits — so a field that never needed shrinking is never
 * measured twice.
 */
export function fitFontSize(text: string, opts: FitOptions): number {
  const { basePx, maxWidthPx, maxHeightPx, bold, fontFamily } = opts;

  if (maxHeightPx <= 0 || basePx <= 0 || maxWidthPx <= 0 || text.trim() === "") {
    return basePx;
  }

  // SSR and the thumbnail capture have no document to measure against; the
  // unshrunk size is the honest answer there rather than a guess.
  if (typeof document === "undefined") return basePx;

  const el = measuringElement();

  el.style.width = `${maxWidthPx}px`;
  el.style.fontWeight = bold ? "700" : "400";
  el.style.fontFamily = fontFamily ?? "inherit";
  el.textContent = text;

  let size = basePx;

  for (let i = 0; i < 20; i++) {
    el.style.fontSize = `${size}px`;

    if (el.scrollHeight <= maxHeightPx) return size;

    // Same factor as the renderer, so the two bottom out at the same ~44% of
    // the requested size. A different step would make the preview and the print
    // disagree by more than the font difference already does.
    size *= 0.96;
  }

  return size;
}
