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
