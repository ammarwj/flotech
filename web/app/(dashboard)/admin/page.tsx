"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import {
  CreditCard,
  Building2,
  ArrowRight,
  ShieldCheck,
  SlidersHorizontal,
  Settings2,
  Trophy,
  BarChart3,
  Zap,
} from "lucide-react";

import { getAdminStats } from "@/lib/api/admin";
import { getAdminPlans } from "@/lib/api/plans";
import { getAdminViewStats, LIVE_STATS_OPTIONS } from "@/lib/api/views";
import { angka, EVENT_STATUS_LABELS } from "@/lib/labels";
import type { EventStatus } from "@/types/api";
import { useAuthStore } from "@/stores/auth-store";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/shared/page-header";
import { StatCard } from "@/components/shared/stat-card";

export default function AdminOverviewPage() {
  const user = useAuthStore((s) => s.user);
  const plansQuery = useQuery({ queryKey: ["admin-plans"], queryFn: getAdminPlans });
  const viewsQuery = useQuery({
    queryKey: ["admin-view-stats"],
    queryFn: getAdminViewStats,
    ...LIVE_STATS_OPTIONS,
  });
  const statsQuery = useQuery({
    queryKey: ["admin-stats"],
    queryFn: getAdminStats,
    ...LIVE_STATS_OPTIONS,
  });

  const events = statsQuery.data?.events;
  // "Aktif" di sini adalah definisi yang sama dengan kuota paket (belum selesai
  // & belum dibatalkan); sisanya = yang sudah selesai atau batal.
  const closedEvents = events ? events.total - events.active : 0;

  const planCount = plansQuery.data?.length ?? 0;
  const activePlans = plansQuery.data?.filter((p) => p.is_active).length ?? 0;
  // The card shows the window the trend covers, not the all-time total, so the
  // number matches the chart on /admin/visitors.
  const recentViews = viewsQuery.data?.trend.reduce((sum, p) => sum + p.views, 0) ?? 0;

  return (
    <div>
      <PageHeader
        title={
          <span className="inline-flex items-center gap-2">
            <ShieldCheck className="h-5 w-5 text-[var(--brand-600)]" />
            Admin Platform
          </span>
        }
        description={`Halo ${user?.full_name ?? "Super Admin"} — kelola paket langganan dan konfigurasi platform flo-event.`}
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Total turnamen"
          value={angka(events?.total ?? 0)}
          icon={Trophy}
          loading={statsQuery.isLoading}
          hint={
            events
              ? `${angka(events.active)} aktif · ${angka(closedEvents)} selesai/batal`
              : undefined
          }
        />
        <StatCard
          label="Turnamen aktif"
          value={angka(events?.active ?? 0)}
          icon={Zap}
          color="var(--accent-green)"
          loading={statsQuery.isLoading}
          hint={events ? `${angka(events.ongoing)} sedang berlangsung` : undefined}
        />
        <StatCard
          label="Kunjungan 30 hari"
          value={angka(recentViews)}
          icon={BarChart3}
          color="var(--accent-purple)"
          loading={viewsQuery.isLoading}
          hint={
            <Link href="/admin/visitors" className="text-[var(--brand-600)] hover:underline">
              Lihat statistik pengunjung
            </Link>
          }
        />
        <StatCard
          label="Total paket"
          value={planCount}
          icon={CreditCard}
          loading={plansQuery.isLoading}
          hint={`${activePlans} paket aktif`}
        />
      </div>

      {events && (
        <Card className="mt-4 p-5">
          <h3 className="text-sm font-semibold">Turnamen per status</h3>
          <dl className="mt-3 flex flex-wrap gap-2">
            {/* Urutan & label dari EVENT_STATUS_LABELS — satu sumber label
                status, sama seperti yang dibaca halaman event. */}
            {(Object.keys(EVENT_STATUS_LABELS) as EventStatus[]).map((status) => (
              <div
                key={status}
                className="flex items-center gap-2 rounded-lg bg-[var(--tint)] px-3 py-1.5 text-sm"
              >
                <dt className="text-muted-foreground">{EVENT_STATUS_LABELS[status]}</dt>
                <dd className="font-semibold">{angka(events.by_status[status] ?? 0)}</dd>
              </div>
            ))}
          </dl>
        </Card>
      )}

      <Card className="mt-6 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <CreditCard className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Paket & fitur langganan
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Buat dan atur paket, harga, serta batas fitur (event aktif, tim, tiket, sertifikat).
            </p>
          </div>
        </div>
        <Button asChild className="shrink-0">
          <Link href="/admin/plans">
            Kelola paket
            <ArrowRight className="h-4 w-4" />
          </Link>
        </Button>
      </Card>

      <Card className="mt-4 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <SlidersHorizontal className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Definisi fitur
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Kelola katalog fitur (kunci, label, tipe) yang dipakai saat mengatur batas tiap paket.
            </p>
          </div>
        </div>
        <Button asChild variant="outline" className="shrink-0">
          <Link href="/admin/feature-definitions">
            Kelola fitur
            <ArrowRight className="h-4 w-4" />
          </Link>
        </Button>
      </Card>

      <Card className="mt-4 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Trophy className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Cabang olahraga
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Tambah cabang baru beserta gaya skor, durasi match, dan kolom statistiknya — langsung
              bisa dipakai organizer tanpa deploy.
            </p>
          </div>
        </div>
        <Button asChild variant="outline" className="shrink-0">
          <Link href="/admin/sports">
            Kelola cabang
            <ArrowRight className="h-4 w-4" />
          </Link>
        </Button>
      </Card>

      <Card className="mt-4 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Settings2 className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Opsi konfigurasi
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Format turnamen (termasuk preset seperti “Liga 2 Putaran”), tie breaker, metode
              undian, babak knockout, dan tier sponsor.
            </p>
          </div>
        </div>
        <Button asChild variant="outline" className="shrink-0">
          <Link href="/admin/config-options">
            Kelola opsi
            <ArrowRight className="h-4 w-4" />
          </Link>
        </Button>
      </Card>

      <Card className="mt-4 flex items-start gap-3 p-6 text-sm text-muted-foreground">
        <Building2 className="mt-0.5 h-5 w-5 shrink-0" />
        <p>
          Kamu masuk sebagai <span className="font-semibold text-foreground">Super Admin</span>. Area
          organizer (event, tim, tiket) tidak ditampilkan untuk peran ini.
        </p>
      </Card>
    </div>
  );
}
