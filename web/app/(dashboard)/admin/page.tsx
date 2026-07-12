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
} from "lucide-react";

import { getAdminPlans } from "@/lib/api/plans";
import { useAuthStore } from "@/stores/auth-store";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/shared/page-header";

export default function AdminOverviewPage() {
  const user = useAuthStore((s) => s.user);
  const plansQuery = useQuery({ queryKey: ["admin-plans"], queryFn: getAdminPlans });

  const planCount = plansQuery.data?.length ?? 0;
  const activePlans = plansQuery.data?.filter((p) => p.is_active).length ?? 0;

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

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted-foreground">Total paket</span>
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
              <CreditCard className="h-[18px] w-[18px]" />
            </span>
          </div>
          <div className="mt-3 text-3xl font-extrabold" style={{ fontFamily: "var(--font-display)" }}>
            {plansQuery.isLoading ? "…" : planCount}
          </div>
          <p className="mt-1 text-xs text-muted-foreground">{activePlans} paket aktif</p>
        </Card>
      </div>

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
