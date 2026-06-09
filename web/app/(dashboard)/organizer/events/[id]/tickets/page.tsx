"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  Plus,
  Ticket,
  Pencil,
  Trash2,
  ArrowUpRight,
  Wallet,
  ScanLine,
  Users,
  TrendingUp,
} from "lucide-react";

import {
  createTicketCategory,
  deleteTicketCategory,
  getTicketCategories,
  getTicketReport,
  updateTicketCategory,
  type TicketCategoryInput,
} from "@/lib/api/tickets";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { isTicketingEnabled, getTicketLimit } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { TicketCategoryForm } from "@/components/event/ticket-category-form";
import { rupiah } from "@/lib/labels";
import type { TicketCategory } from "@/types/api";

export default function EventTicketsPage() {
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { org, orgId } = useActiveOrg();

  const [editing, setEditing] = useState<TicketCategory | null>(null);
  const [creating, setCreating] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const ticketing = isTicketingEnabled(org);
  const limit = getTicketLimit(org);

  const catsQuery = useQuery({
    queryKey: ["ticket-categories", orgId, eventId],
    queryFn: () => getTicketCategories(orgId!, eventId),
    enabled: !!orgId && ticketing,
  });

  const reportQuery = useQuery({
    queryKey: ["ticket-report", orgId, eventId],
    queryFn: () => getTicketReport(orgId!, eventId),
    enabled: !!orgId && ticketing,
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["ticket-categories", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["ticket-report", orgId, eventId] });
  };

  const handleError = (err: unknown, fallback: string) => {
    const parsed = parseApiError(err, fallback);
    setFieldErrors(parsed.fieldErrors);
    toast.error(parsed.message);
  };

  const createMut = useMutation({
    mutationFn: (payload: TicketCategoryInput) => createTicketCategory(orgId!, eventId, payload),
    onSuccess: () => {
      toast.success("Kategori tiket dibuat.");
      setCreating(false);
      setFieldErrors({});
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal membuat kategori."),
  });

  const updateMut = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: TicketCategoryInput }) =>
      updateTicketCategory(orgId!, id, payload),
    onSuccess: () => {
      toast.success("Kategori tiket diperbarui.");
      setEditing(null);
      setFieldErrors({});
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal memperbarui kategori."),
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => deleteTicketCategory(orgId!, id),
    onSuccess: () => {
      toast.success("Kategori tiket dihapus.");
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal menghapus kategori."),
  });

  // ---- Plan gate: ticketing not on this plan ----
  if (org && !ticketing) {
    return (
      <div>
        <PageHeader
          title="Tiket"
          description="Jual tiket digital dengan QR Code untuk event ini."
          backHref="/organizer/events"
          backLabel="Daftar event"
        />
        <EmptyState
          icon={Ticket}
          title="Fitur tiket belum aktif di paketmu"
          description="Upgrade ke paket Starter atau lebih tinggi untuk menjual tiket QR Code dan mengelola check-in penonton."
          action={
            <Button asChild>
              <Link href="/organizer/upgrade">
                <ArrowUpRight className="h-4 w-4" />
                Upgrade paket
              </Link>
            </Button>
          }
        />
      </div>
    );
  }

  const categories = catsQuery.data;
  const report = reportQuery.data;
  const totalQuota = categories?.reduce((s, c) => s + (c.quota ?? 0), 0) ?? 0;

  return (
    <div>
      <PageHeader
        title="Tiket"
        description={
          limit !== null
            ? `Kelola kategori tiket & pantau penjualan. Batas paket: ${limit.toLocaleString("id-ID")} tiket/event.`
            : "Kelola kategori tiket & pantau penjualan event ini."
        }
        backHref="/organizer/events"
        backLabel="Daftar event"
        actions={
          <Button asChild variant="outline">
            <Link href={`/organizer/events/${eventId}/scan`}>
              <ScanLine className="h-4 w-4" />
              Scan check-in
            </Link>
          </Button>
        }
      />

      {/* ===== Sales & check-in summary ===== */}
      {reportQuery.isLoading ? (
        <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-[92px] w-full rounded-xl" />
          ))}
        </div>
      ) : report ? (
        <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            icon={Wallet}
            label="Pendapatan kotor"
            value={rupiah(report.finance.gross_revenue)}
            color="var(--brand-600)"
          />
          <StatCard
            icon={TrendingUp}
            label="Biaya platform"
            value={rupiah(report.finance.platform_fee)}
            color="var(--sport-padel)"
          />
          <StatCard
            icon={Ticket}
            label="Tiket terjual"
            value={String(report.finance.tickets_sold)}
            color="var(--sport-futsal)"
          />
          <StatCard
            icon={Users}
            label="Check-in"
            value={`${report.checkin.checked_in} / ${report.checkin.total}`}
            color="var(--success)"
          />
        </div>
      ) : null}

      {/* ===== Category management ===== */}
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
          Kategori Tiket
        </h2>
        {!creating && !editing && (
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" />
            Tambah kategori
          </Button>
        )}
      </div>

      {creating && (
        <Card className="mb-4 p-5">
          <h3 className="mb-4 font-semibold">Kategori baru</h3>
          <TicketCategoryForm
            onSubmit={(payload) => createMut.mutate(payload)}
            onCancel={() => {
              setCreating(false);
              setFieldErrors({});
            }}
            pending={createMut.isPending}
            fieldErrors={fieldErrors}
          />
        </Card>
      )}

      {catsQuery.isLoading && (
        <div className="grid gap-3">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-[80px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {categories?.length === 0 && !creating && (
        <EmptyState
          icon={Ticket}
          title="Belum ada kategori tiket"
          description="Buat kategori pertama (mis. Reguler / VIP) agar penonton bisa membeli tiket."
          action={
            <Button onClick={() => setCreating(true)}>
              <Plus className="h-4 w-4" />
              Tambah kategori
            </Button>
          }
        />
      )}

      <div className="grid gap-3">
        {categories?.map((cat) =>
          editing?.id === cat.id ? (
            <Card key={cat.id} className="p-5">
              <h3 className="mb-4 font-semibold">Edit “{cat.name}”</h3>
              <TicketCategoryForm
                initial={cat}
                onSubmit={(payload) => updateMut.mutate({ id: cat.id, payload })}
                onCancel={() => {
                  setEditing(null);
                  setFieldErrors({});
                }}
                pending={updateMut.isPending}
                fieldErrors={fieldErrors}
              />
            </Card>
          ) : (
            <Card key={cat.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                    {cat.name}
                  </span>
                  <Badge variant={cat.is_active ? "success" : "neutral"}>
                    {cat.is_active ? "Aktif" : "Nonaktif"}
                  </Badge>
                  {cat.is_on_sale ? (
                    <Badge variant="info">Dijual</Badge>
                  ) : (
                    <Badge variant="outline">Belum dijual</Badge>
                  )}
                </div>
                <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                  <span className="font-semibold text-foreground">{rupiah(cat.price)}</span>
                  <span>
                    Terjual {cat.sold}
                    {cat.quota != null ? ` / ${cat.quota}` : " (tak terbatas)"}
                  </span>
                  {cat.benefits.length > 0 && <span>{cat.benefits.join(" · ")}</span>}
                </div>
              </div>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setEditing(cat);
                    setCreating(false);
                    setFieldErrors({});
                  }}
                >
                  <Pencil className="h-4 w-4" />
                  Edit
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    if (confirm(`Hapus kategori “${cat.name}”?`)) deleteMut.mutate(cat.id);
                  }}
                  disabled={deleteMut.isPending || cat.sold > 0}
                  title={cat.sold > 0 ? "Tidak bisa dihapus karena sudah ada tiket terjual" : undefined}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </Card>
          )
        )}
      </div>

      {limit !== null && categories && categories.length > 0 && (
        <p className="mt-3 text-xs text-muted-foreground">
          Total kuota terpasang: {totalQuota.toLocaleString("id-ID")} / {limit.toLocaleString("id-ID")} tiket.
        </p>
      )}

      {/* ===== Recent check-ins ===== */}
      {report && report.recent_checkins.length > 0 && (
        <div className="mt-10">
          <h2 className="mb-4 text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
            Check-in Terbaru
          </h2>
          <Card className="divide-y divide-border p-0">
            {report.recent_checkins.map((c) => (
              <div key={c.id} className="flex items-center justify-between px-4 py-3 text-sm">
                <span className="font-medium">{c.holder_name ?? "Tanpa nama"}</span>
                <span className="text-muted-foreground">{c.category}</span>
                <span className="text-muted-foreground">
                  {c.used_at ? new Date(c.used_at).toLocaleString("id-ID") : ""}
                </span>
              </div>
            ))}
          </Card>
        </div>
      )}
    </div>
  );
}

function StatCard({
  icon: Icon,
  label,
  value,
  color,
}: {
  icon: typeof Wallet;
  label: string;
  value: string;
  color: string;
}) {
  return (
    <Card className="p-4">
      <div className="flex items-center gap-3">
        <span
          className="grid h-10 w-10 shrink-0 place-items-center rounded-xl"
          style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}
        >
          <Icon className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <div className="text-xs text-muted-foreground">{label}</div>
          <div className="truncate text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {value}
          </div>
        </div>
      </div>
    </Card>
  );
}
