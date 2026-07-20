"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Trophy, Users, Ticket, Award, Plus, ArrowRight, Eye, BarChart3 } from "lucide-react";


import { getOrgViewStats, LIVE_STATS_OPTIONS } from "@/lib/api/views";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { angka } from "@/lib/labels";
import { PageHeader } from "@/components/shared/page-header";
import { StatCard } from "@/components/shared/stat-card";
import { TrendChart } from "@/components/shared/trend-chart";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";

export default function DashboardPage() {
  const { orgId } = useActiveOrg();

  const viewsQuery = useQuery({
    queryKey: ["org-view-stats", orgId],
    queryFn: () => getOrgViewStats(orgId!),
    enabled: !!orgId,
    ...LIVE_STATS_OPTIONS,
  });

  const views = viewsQuery.data;
  const loading = viewsQuery.isLoading || !orgId;

  return (
    <div>
      <RedirectIfAdmin />
      <PageHeader
        title="Selamat datang 👋"
        description="Ringkasan aktivitas turnamenmu. Buat event, kelola pendaftaran, dan pantau semuanya dari sini."
        actions={
          <Button asChild>
            <Link href="/organizer/events/new">
              <Plus className="h-4 w-4" />
              Buat Event
            </Link>
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard
          label="Kunjungan halaman"
          value={angka(views?.totals.views ?? 0)}
          icon={Eye}
          loading={loading}
        />
        <StatCard
          label="Pengunjung unik"
          value={angka(views?.totals.unique_visitors ?? 0)}
          icon={Users}
          color="var(--accent-purple)"
          loading={loading}
        />
        {/* Placeholders as before — wiring these up is separate work. */}
        <StatCard label="Event Aktif" value="0" icon={Trophy} />
        <StatCard label="Tim Terdaftar" value="0" icon={Users} color="var(--accent-green)" />
        <StatCard label="Tiket Terjual" value="0" icon={Ticket} color="var(--accent-purple)" />
        <StatCard label="Sertifikat" value="0" icon={Award} color="var(--plan-professional)" />
      </div>

      <Card className="mt-6 p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Kunjungan 30 hari terakhir
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Gabungan seluruh halaman publik event milikmu.
            </p>
          </div>
          <Button asChild variant="outline" size="sm" className="shrink-0">
            <Link href="/organizer/events">
              <BarChart3 className="h-4 w-4" />
              Per event
            </Link>
          </Button>
        </div>
        <div className="mt-5">
          {loading ? (
            <Skeleton className="h-[180px] w-full" />
          ) : (
            <TrendChart points={views?.trend ?? []} />
          )}
        </div>
      </Card>

      {views?.events.length ? (
        <Card className="mt-4 p-6">
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Event paling banyak dilihat
          </h3>
          <div className="mt-4 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  <th className="pb-2 font-medium">Event</th>
                  <th className="pb-2 text-right font-medium">Kunjungan</th>
                  <th className="pb-2 text-right font-medium">Pengunjung</th>
                  <th className="pb-2" />
                </tr>
              </thead>
              <tbody>
                {views.events.slice(0, 5).map((ev) => (
                  <tr key={ev.event_id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pr-3 font-medium">{ev.name}</td>
                    <td className="py-2.5 text-right tabular-nums">{angka(ev.views)}</td>
                    <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                      {angka(ev.unique_visitors)}
                    </td>
                    <td className="py-2.5 pl-3 text-right">
                      <Link
                        href={`/organizer/events/${ev.event_id}/stats`}
                        className="text-xs font-medium text-[var(--brand-600)] hover:underline"
                      >
                        Detail
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      ) : (
        <Card className="mt-4 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Mulai turnamen pertamamu
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Buat event, atur format, lalu buka pendaftaran tim dalam hitungan menit.
            </p>
          </div>
          <Button asChild variant="outline" className="shrink-0">
            <Link href="/organizer/events">
              Kelola Event
              <ArrowRight className="h-4 w-4" />
            </Link>
          </Button>
        </Card>
      )}
    </div>
  );
}
