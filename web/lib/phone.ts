/**
 * Keep phone-number input to what a phone number actually is: digits, with an
 * optional single leading "+" for the country code (e.g. +62). No letters.
 *
 * Run this on every keystroke so pasted junk is cleaned too. Pair it with
 * `inputMode="tel"` on the input for a numeric keypad on mobile.
 */
export function phoneInput(v: string): string {
  return v.replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
}
