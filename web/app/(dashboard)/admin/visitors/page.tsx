"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BarChart3, ChevronDown, Eye, Search as SearchIcon, Users, X } from "lucide-react";

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
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatCard } from "@/components/shared/stat-card";
import { TrendChart } from "@/components/shared/trend-chart";

/** Rows per page, and how many more each "tampilkan lebih banyak" asks for. */
const PAGE_SIZE = 20;

/**
 * Both tables are ordered by traffic, so a quiet event sits at the bottom of a
 * long tail — DIRAY CUP 10 was rank 23 of 24 and simply wasn't on the page,
 * which reads as traffic that was never recorded rather than as a list that
 * was cut. Hence the search box (server-side: filtering the fetched rows would
 * only search the busiest ones) and the explicit "lebih banyak" below each.
 */
function SearchBox({
  value,
  onChange,
  placeholder,
  label,
}: {
  value: string;
  onChange: (next: string) => void;
  placeholder: string;
  label: string;
}) {
  return (
    <div className="relative min-w-0 sm:max-w-xs sm:flex-1">
      <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="pl-9 pr-9"
        aria-label={label}
      />
      {value && (
        <button
          type="button"
          onClick={() => onChange("")}
          aria-label="Hapus pencarian"
          className="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <X className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}

/** Debounce a search box so a request isn't fired per keystroke. */
function useDebounced(value: string, onSettled: () => void, delay = 350) {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const t = setTimeout(() => {
      setSettled(value);
      onSettled();
    }, delay);
    return () => clearTimeout(t);
    // onSettled resets the page size; it is stable enough to leave out, and
    // including an inline arrow would re-arm the timer on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, delay]);

  return settled;
}

export default function AdminVisitorsPage() {
  // Clicking an organization row narrows the event table below it — the same
  // endpoint, filtered, rather than a separate drill-down screen.
  const [orgFilter, setOrgFilter] = useState<{ id: string; name: string } | null>(null);

  const [orgSearch, setOrgSearch] = useState("");
  const [eventSearch, setEventSearch] = useState("");
  const [orgLimit, setOrgLimit] = useState(PAGE_SIZE);
  const [eventLimit, setEventLimit] = useState(PAGE_SIZE);

  // A new term is a new list: keeping the grown limit would make the first
  // keystroke fetch hundreds of rows nobody asked for.
  const orgQ = useDebounced(orgSearch, () => setOrgLimit(PAGE_SIZE));
  const eventQ = useDebounced(eventSearch, () => setEventLimit(PAGE_SIZE));

  const totalsQuery = useQuery({
    queryKey: ["admin-view-stats"],
    queryFn: getAdminViewStats,
    ...LIVE_STATS_OPTIONS,
  });

  const orgsQuery = useQuery({
    queryKey: ["admin-views-by-org", orgQ, orgLimit],
    queryFn: () => getAdminViewsByOrganization({ limit: orgLimit, q: orgQ || undefined }),
    ...LIVE_STATS_OPTIONS,
  });

  const eventsQuery = useQuery({
    queryKey: ["admin-views-by-event", orgFilter?.id ?? null, eventQ, eventLimit],
    queryFn: () =>
      getAdminViewsByEvent({
        organization_id: orgFilter?.id,
        limit: eventLimit,
        q: eventQ || undefined,
      }),
    ...LIVE_STATS_OPTIONS,
  });

  const totals = totalsQuery.data;
  const orgRows = orgsQuery.data?.items ?? [];
  const eventRows = eventsQuery.data?.items ?? [];

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
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Per organizer
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Klik satu baris untuk menyaring tabel event di bawah.
            </p>
          </div>
          <SearchBox
            value={orgSearch}
            onChange={setOrgSearch}
            placeholder="Cari nama organizer…"
            label="Cari organizer"
          />
        </div>

        <div className="mt-4 overflow-x-auto">
          {orgsQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : orgRows.length ? (
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
                {orgRows.map((row) => {
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
              {orgQ
                ? `Tidak ada organizer dengan trafik yang cocok dengan "${orgQ}".`
                : "Belum ada trafik yang tercatat."}
            </p>
          )}
        </div>

        {/* Said out loud, because a list cut in silence reads as missing data. */}
        {orgsQuery.data?.has_more && (
          <div className="mt-3 flex justify-center">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setOrgLimit((n) => n + PAGE_SIZE)}
              disabled={orgsQuery.isFetching}
            >
              <ChevronDown className="h-4 w-4" />
              {orgsQuery.isFetching ? "Memuat…" : "Tampilkan lebih banyak"}
            </Button>
          </div>
        )}
      </Card>

      <Card className="mt-4 p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex flex-wrap items-center gap-3">
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
          <SearchBox
            value={eventSearch}
            onChange={setEventSearch}
            placeholder="Cari nama event atau organizer…"
            label="Cari event"
          />
        </div>

        <div className="mt-4 overflow-x-auto">
          {eventsQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : eventRows.length ? (
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
                {eventRows.map((row) => (
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
              {eventQ
                ? `Tidak ada event dengan trafik yang cocok dengan "${eventQ}".`
                : orgFilter
                  ? "Organizer ini belum punya trafik yang tercatat."
                  : "Belum ada trafik yang tercatat."}
            </p>
          )}
        </div>

        {eventsQuery.data?.has_more && (
          <div className="mt-3 flex justify-center">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setEventLimit((n) => n + PAGE_SIZE)}
              disabled={eventsQuery.isFetching}
            >
              <ChevronDown className="h-4 w-4" />
              {eventsQuery.isFetching ? "Memuat…" : "Tampilkan lebih banyak"}
            </Button>
          </div>
        )}
      </Card>
    </div>
  );
}
