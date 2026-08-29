import type { AvatarPreset } from "@/types/api";

/**
 * Testimonial avatars are initials on a gradient, not photos. The API stores only
 * the preset key so the CSS vars stay defined once in globals.css — resolve it
 * here, both on the landing page and in the admin editor's preview.
 */
export const AVATAR_PRESETS: Record<AvatarPreset, { label: string; gradient: string }> = {
  brand: { label: "Brand", gradient: "linear-gradient(135deg,var(--brand-500),var(--brand-700))" },
  purple: { label: "Ungu", gradient: "linear-gradient(135deg,var(--accent-purple),#5B21B6)" },
  pink: { label: "Pink", gradient: "linear-gradient(135deg,var(--accent-pink),#9D174D)" },
  success: { label: "Hijau", gradient: "linear-gradient(135deg,var(--success),#047857)" },
  amber: { label: "Amber", gradient: "linear-gradient(135deg,var(--plan-professional),#B45309)" },
  blue: { label: "Biru", gradient: "linear-gradient(135deg,var(--accent-sky),#0369A1)" },
};

/** Falls back to `brand` so an unknown preset renders a card instead of `undefined`. */
export function avatarGradient(preset: AvatarPreset): string {
  return (AVATAR_PRESETS[preset] ?? AVATAR_PRESETS.brand).gradient;
}

/**
 * Landing-page counters, shortened the way Indonesian copy shortens them:
 * 940 → "940", 38.400 → "38rb", 1.240.000 → "1,2jt".
 *
 * Lives here rather than in the component for the same reason avatarGradient
 * does — the API ships raw numbers, and there is exactly one way the site
 * writes them.
 */
export function compactCount(value: number): string {
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toLocaleString("id-ID", { maximumFractionDigits: 1 })}jt`;
  }

  if (value >= 1_000) {
    return `${Math.floor(value / 1_000).toLocaleString("id-ID")}rb`;
  }

  return value.toLocaleString("id-ID");
}
