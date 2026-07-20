"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BarChart3, Eye, Users, X } from "lucide-react";

import {
  getAdminViewStats,
  getAdminViewsByEvent,
  getAdminViewsByOrganization,
  LIVE_STATS_OPTIONS,
} from "@/lib/api/views";
import { angka } from "@/lib/labels";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatCard } from "@/components/shared/stat-card";
import { TrendChart } from "@/components/shared/trend-chart";

export default function AdminVisitorsPage() {
  // Clicking an organization row narrows the event table below it — the same
  // endpoint, filtered, rather than a separate drill-down screen.
  const [orgFilter, setOrgFilter] = useState<{ id: string; name: string } | null>(null);

  const totalsQuery = useQuery({
    queryKey: ["admin-view-stats"],
    queryFn: getAdminViewStats,
    ...LIVE_STATS_OPTIONS,
  });

  const orgsQuery = useQuery({
    queryKey: ["admin-views-by-org"],
    queryFn: () => getAdminViewsByOrganization(20),
    ...LIVE_STATS_OPTIONS,
  });

  const eventsQuery = useQuery({
    queryKey: ["admin-views-by-event", orgFilter?.id ?? null],
    queryFn: () => getAdminViewsByEvent({ organization_id: orgFilter?.id }),
    ...LIVE_STATS_OPTIONS,
  });

  const totals = totalsQuery.data;

  return (
    <div>
      <PageHeader
        title={
          <span className="inline-flex items-center gap-2">
            <BarChart3 className="h-5 w-5 text-[var(--brand-600)]" />
            Statistik Pengunjung
          </span>
        }
        description="Trafik halaman publik event di seluruh platform. Kunjungan menghitung setiap kali halaman dibuka; pengunjung unik menghitung tiap orang sekali per hari."
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <StatCard
          label="Total kunjungan"
          value={angka(totals?.totals.views ?? 0)}
          icon={Eye}
          loading={totalsQuery.isLoading}
          hint="Seluruh event, sepanjang waktu"
        />
        <StatCard
          label="Pengunjung unik"
          value={angka(totals?.totals.unique_visitors ?? 0)}
          icon={Users}
          color="var(--accent-purple)"
          loading={totalsQuery.isLoading}
          hint="Dihitung sekali per orang per hari"
        />
      </div>

      <Card className="mt-6 p-6">
        <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
          30 hari terakhir
        </h3>
        <div className="mt-5">
          {totalsQuery.isLoading ? (
            <Skeleton className="h-[180px] w-full" />
          ) : (
            <TrendChart points={totals?.trend ?? []} />
          )}
        </div>
      </Card>

      <Card className="mt-4 p-6">
        <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Per organizer
        </h3>
        <p className="mt-1 text-sm text-muted-foreground">
          Klik satu baris untuk menyaring tabel event di bawah.
        </p>

        <div className="mt-4 overflow-x-auto">
          {orgsQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : orgsQuery.data?.length ? (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  <th className="pb-2 font-medium">Organizer</th>
                  <th className="pb-2 text-right font-medium">Event</th>
                  <th className="pb-2 text-right font-medium">Kunjungan</th>
                  <th className="pb-2 text-right font-medium">Pengunjung</th>
                </tr>
              </thead>
              <tbody>
                {orgsQuery.data.map((row) => {
                  const selected = orgFilter?.id === row.organization_id;
                  return (
                    <tr
                      key={row.organization_id}
                      onClick={() =>
                        setOrgFilter(
                          selected ? null : { id: row.organization_id, name: row.name }
                        )
                      }
                      className={cn(
                        "cursor-pointer border-b border-border last:border-0 transition-colors hover:bg-[var(--bg-alt)]",
                        selected && "bg-[var(--tint)]"
                      )}
                    >
                      <td className="py-2.5 pr-3 font-medium">{row.name}</td>
                      <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                        {angka(row.events_count)}
                      </td>
                      <td className="py-2.5 text-right tabular-nums">{angka(row.views)}</td>
                      <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                        {angka(row.unique_visitors)}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          ) : (
            <p className="py-6 text-center text-sm text-muted-foreground">
              Belum ada trafik yang tercatat.
            </p>
          )}
        </div>
      </Card>

      <Card className="mt-4 p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Per event
          </h3>
          {orgFilter && (
            <Button variant="outline" size="sm" onClick={() => setOrgFilter(null)}>
              <X className="h-4 w-4" />
              {orgFilter.name}
            </Button>
          )}
        </div>

        <div className="mt-4 overflow-x-auto">
          {eventsQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : eventsQuery.data?.length ? (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  <th className="pb-2 font-medium">Event</th>
                  <th className="pb-2 font-medium">Organizer</th>
                  <th className="pb-2 text-right font-medium">Kunjungan</th>
                  <th className="pb-2 text-right font-medium">Pengunjung</th>
                </tr>
              </thead>
              <tbody>
                {eventsQuery.data.map((row) => (
                  <tr key={row.event_id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pr-3 font-medium">{row.name}</td>
                    <td className="py-2.5 pr-3 text-muted-foreground">{row.organization_name}</td>
                    <td className="py-2.5 text-right tabular-nums">{angka(row.views)}</td>
                    <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                      {angka(row.unique_visitors)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="py-6 text-center text-sm text-muted-foreground">
              {orgFilter
                ? "Organizer ini belum punya trafik yang tercatat."
                : "Belum ada trafik yang tercatat."}
            </p>
          )}
        </div>
      </Card>
    </div>
  );
}
