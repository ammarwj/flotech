/**
 * Where to land after signing in, from `?next=`.
 *
 * Only same-site paths are honoured. A `next` that starts with `//` or a scheme
 * would send the user to another origin straight after they typed their
 * password, which is the classic open-redirect phishing setup — those are
 * dropped rather than followed.
 */
export function safeNext(next: string | null | undefined): string | null {
  if (!next || !next.startsWith("/") || next.startsWith("//")) return null;
  return next;
}
