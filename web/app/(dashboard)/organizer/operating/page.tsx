"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { CalendarDays, ClipboardList } from "lucide-react";

import { getMyOperatorEvents } from "@/lib/api/event-operators";
import {
  OPERATOR_CATEGORY_LABELS,
  grantedCategories,
} from "@/lib/event-operator-permissions";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Landing page for an account whose only identity on the platform is "event
 * operator" — no `organization_members` seat, so none of `/organizer`'s own
 * stats (which all key off `useActiveOrg()`) mean anything for them.
 *
 * Exactly one operated event → skip the list and go straight there, same as
 * what accepting an invite already does. More than one → show the picker, so
 * a second or third assignment never strands the account on a page with
 * nothing to click. See docs/event-operator-design.md section 9.
 */
export default function OperatingLandingPage() {
  const router = useRouter();

  const query = useQuery({
    queryKey: ["my-operator-events"],
    queryFn: getMyOperatorEvents,
  });

  const events = query.data ?? [];
  const single = events.length === 1 ? events[0] : null;

  useEffect(() => {
    if (single) router.replace(`/organizer/events/${single.event.id}/edit`);
  }, [single, router]);

  if (query.isLoading || single) {
    return (
      <div className="grid gap-3">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-24 w-full" />
      </div>
    );
  }

  if (events.length === 0) {
    return (
      <EmptyState
        icon={ClipboardList}
        title="Belum ada event yang kamu kelola"
        description="Kamu akan melihatnya di sini begitu organizer mengundangmu sebagai operator untuk sebuah event."
      />
    );
  }

  return (
    <div>
      <PageHeader
        title="Event yang Kamu Kelola"
        description="Kamu diundang sebagai operator untuk event-event ini. Pilih salah satu untuk mulai."
      />

      <div className="grid gap-3">
        {events.map((item) => (
          <Link key={item.event.id} href={`/organizer/events/${item.event.id}/edit`}>
            <Card className="flex flex-col gap-3 p-4 transition-colors hover:border-[var(--brand-600)] sm:flex-row sm:items-center sm:justify-between">
              <div className="flex items-center gap-3 min-w-0">
                <div className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
                  <CalendarDays className="h-5 w-5" />
                </div>
                <div className="min-w-0">
                  <p className="truncate font-semibold">{item.event.name}</p>
                  <p className="text-xs text-muted-foreground">{item.organization.name}</p>
                </div>
              </div>
              <div className="flex flex-wrap gap-1.5">
                {grantedCategories(item).map((c) => (
                  <Badge key={c} variant="outline">
                    {OPERATOR_CATEGORY_LABELS[c]}
                  </Badge>
                ))}
              </div>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}
