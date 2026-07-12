import type { LucideIcon } from "lucide-react";

import { CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/** Card header with an accented icon tile, title, and short description. */
export function SectionHeader({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  /** Optional control pinned to the right of the header. */
  action?: React.ReactNode;
}) {
  return (
    <CardHeader className="flex-row items-start gap-3 space-y-0">
      <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
        <Icon className="h-5 w-5" />
      </span>
      <div className="min-w-0 flex-1">
        <CardTitle>{title}</CardTitle>
        <CardDescription className="mt-1">{description}</CardDescription>
      </div>
      {action}
    </CardHeader>
  );
}
