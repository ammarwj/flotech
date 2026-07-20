"use client";

import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Eye, Users } from "lucide-react";

import { getEventViewStats, LIVE_STATS_OPTIONS } from "@/lib/api/views";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { angka } from "@/lib/labels";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatCard } from "@/components/shared/stat-card";
import { TrendChart } from "@/components/shared/trend-chart";

export default function EventStatsPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();

  const statsQuery = useQuery({
    queryKey: ["event-view-stats", orgId, eventId],
    queryFn: () => getEventViewStats(orgId!, eventId),
    enabled: !!orgId,
    ...LIVE_STATS_OPTIONS,
  });

  const stats = statsQuery.data;

  return (
    <div>
      <PageHeader
        backHref="/organizer/events"
        backLabel="Kembali ke daftar event"
        title="Statistik Pengunjung"
        description="Berapa banyak orang yang membuka halaman publik event ini. Kunjungan menghitung setiap kali halaman dibuka; pengunjung unik menghitung tiap orang sekali per hari."
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <StatCard
          label="Total kunjungan"
          value={angka(stats?.totals.views ?? 0)}
          icon={Eye}
          loading={statsQuery.isLoading}
          hint="Sepanjang waktu"
        />
        <StatCard
          label="Pengunjung unik"
          value={angka(stats?.totals.unique_visitors ?? 0)}
          icon={Users}
          color="var(--accent-purple)"
          loading={statsQuery.isLoading}
          hint="Dihitung sekali per orang per hari"
        />
      </div>

      <Card className="mt-6 p-6">
        <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
          30 hari terakhir
        </h3>
        <div className="mt-5">
          {statsQuery.isLoading ? (
            <Skeleton className="h-[180px] w-full" />
          ) : (
            <TrendChart points={stats?.trend ?? []} />
          )}
        </div>
      </Card>
    </div>
  );
}
