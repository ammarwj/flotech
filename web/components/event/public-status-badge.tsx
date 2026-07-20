import type { MatchStatus } from "@/types/api";

/**
 * A fixture's status, in the public shell's own badge language.
 *
 * Not the dashboard's <Badge> component on purpose: the public pages are a
 * self-contained stylesheet design system with their own tokens and a fixed
 * 22px badge height, so a Tailwind-utility badge would sit visibly differently
 * in the same row.
 *
 * The wording differs from the organizer's too — a spectator gets "LIVE", not
 * "Berlangsung", and never sees confirmation state, which is internal workflow.
 */
const CHIP: Record<MatchStatus, { label: string; className: string; dot?: boolean }> = {
  scheduled: { label: "Terjadwal", className: "badge badge-neutral" },
  ongoing: { label: "LIVE", className: "badge badge-live", dot: true },
  finished: { label: "Selesai", className: "badge badge-success" },
  cancelled: { label: "Dibatalkan", className: "badge badge-cancelled" },
};

export function PublicStatusBadge({ status }: { status: MatchStatus }) {
  const chip = CHIP[status];

  return (
    <span className={chip.className}>
      {chip.dot && <span className="dot" />}
      {chip.label}
    </span>
  );
}
