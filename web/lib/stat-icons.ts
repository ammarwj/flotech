import { IconBallFootball, IconShoe } from "@tabler/icons-react";
import { RectangleVertical, Shield, Target, Trophy, Zap } from "lucide-react";
import type { ComponentType, SVGProps } from "react";

/**
 * Lucide and Tabler are both drawn on a 24px grid with a 2px stroke and round
 * caps, so the two sets sit side by side in one pill row without looking mixed.
 * Both render a plain <svg>, so callers size and colour them the same way.
 */
type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

export type StatIcon = {
  Icon: IconComponent;
  /** Colour token; absent means the icon inherits the surrounding text colour. */
  color?: string;
  /** Cards are solid shapes — an outlined rectangle doesn't read as a card. */
  filled?: boolean;
};

/**
 * Icon per `sport_stats.stat_key`, for surfaces that show stats as badges
 * rather than a labelled column.
 *
 * Deliberately partial: super_admin can add stat keys at `/admin/sports/{sport}/stats`,
 * so this map can never be exhaustive. Unknown keys return null and the caller
 * falls back to the sport's own `short` text — a custom stat must not render as
 * a blank badge.
 *
 * The two cards differ by colour alone, so every caller has to carry the label
 * in `title`/`aria-label`; the icon is never the only carrier of meaning.
 */
const STAT_ICONS: Record<string, StatIcon> = {
  goals: { Icon: IconBallFootball },
  // No icon set has a real "assist" glyph — a boot is the usual stand-in for
  // the pass. It leans on the label like the cards do.
  assists: { Icon: IconShoe },
  // Hex, bukan token: `--warning` terlalu oranye untuk dibaca sebagai kartu, dan
  // token baru di `:root` yang cuma dirujuk dari inline style di sini akan
  // dibuang Tailwind (custom property tanpa `var()` di CSS di-prune) — hasilnya
  // warna invalid dan ikonnya mewarisi warna teks sekitarnya.
  yellow_cards: { Icon: RectangleVertical, color: "#FACC15", filled: true },
  red_cards: { Icon: RectangleVertical, color: "var(--danger)", filled: true },
  points: { Icon: Target },
  aces: { Icon: Zap },
  blocks: { Icon: Shield },
  winners: { Icon: Trophy },
};

export function statIcon(key: string): StatIcon | null {
  return STAT_ICONS[key] ?? null;
}
