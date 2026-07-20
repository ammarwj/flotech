import type { LucideIcon } from "lucide-react";

import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

/**
 * The dashboard number tile — label, tinted icon, big figure, optional hint.
 *
 * Extracted from the organizer and admin overviews, which had grown identical
 * copies of this markup.
 */
export function StatCard({
  label,
  value,
  icon: Icon,
  color = "var(--brand-600)",
  hint,
  loading = false,
  className,
}: {
  label: string;
  value: React.ReactNode;
  icon: LucideIcon;
  color?: string;
  hint?: React.ReactNode;
  loading?: boolean;
  className?: string;
}) {
  return (
    <Card className={cn("p-5", className)}>
      <div className="flex items-center justify-between">
        <span className="text-sm text-muted-foreground">{label}</span>
        <span
          className="grid h-9 w-9 place-items-center rounded-lg"
          style={{
            background: `color-mix(in srgb, ${color} 14%, transparent)`,
            color,
          }}
        >
          <Icon className="h-[18px] w-[18px]" />
        </span>
      </div>
      <div className="mt-3 text-3xl font-extrabold" style={{ fontFamily: "var(--font-display)" }}>
        {loading ? "…" : value}
      </div>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  );
}
