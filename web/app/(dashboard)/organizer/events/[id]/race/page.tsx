"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CalendarRange, Flag, Pencil, Plus, ScanLine, Trash2, Users } from "lucide-react";

import {
  createRaceCategory,
  deleteRaceCategory,
  getRaceCategories,
  getRaceEntries,
  getRacePeriods,
  updateRaceCategory,
  type RaceCategoryInput,
} from "@/lib/api/race";
import { getEvent } from "@/lib/api/events";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { currentPeriod } from "@/lib/race";
import { isExportEnabled } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useConfirm } from "@/components/shared/confirm-provider";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RaceRouteGuard } from "@/components/event/race-route-guard";
import { RacePeriodsDialog } from "@/components/event/race-periods-dialog";
import { RaceCategoryForm } from "@/components/event/race-category-form";
import { ExportButtons } from "@/components/event/export-buttons";
import type { RaceCategory } from "@/types/api";

/**
 * Everything a fun run organizer sets up and watches: the sale windows, the
 * categories priced against them, and the runners who entered.
 *
 * One page rather than three, because the three are unreadable apart: a price
 * means nothing without the window it belongs to, and a window means nothing
 * until some category charges for it. The ticket side can afford to split
 * categories from buyers — a ticket price is one number.
 */
export default function EventRacePage() {
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();
  const confirm = useConfirm();

  const [editing, setEditing] = useState<RaceCategory | null>(null);
  const [creating, setCreating] = useState(false);
  const [periodsOpen, setPeriodsOpen] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [search, setSearch] = useState("");

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const periodsQuery = useQuery({
    queryKey: ["race-periods", orgId, eventId],
    queryFn: () => getRacePeriods(orgId!, eventId),
    enabled: !!orgId,
  });

  const catsQuery = useQuery({
    queryKey: ["race-categories", orgId, eventId],
    queryFn: () => getRaceCategories(orgId!, eventId),
    enabled: !!orgId,
  });

  const entriesQuery = useQuery({
    queryKey: ["race-entries", orgId, eventId, search],
    queryFn: () => getRaceEntries(orgId!, eventId, { search: search || undefined, per_page: 50 }),
    enabled: !!orgId,
  });

  const periods = periodsQuery.data ?? [];
  const categories = catsQuery.data ?? [];
  const live = currentPeriod(periods);

  /**
   * Editing a window changes what every category costs, so the categories have
   * to be asked again — the price schedule they publish is joined server-side.
   * Invalidated together for the reason the schedule page invalidates the Swiss
   * key alongside the matches: the two answers are one answer.
   */
  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["race-periods", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["race-categories", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["race-entries", orgId, eventId] });
  };

  const handleError = (err: unknown, fallback: string) => {
    const parsed = parseApiError(err, fallback);
    setFieldErrors(parsed.fieldErrors);
    toast.error(parsed.message);
  };

  const createMut = useMutation({
    mutationFn: (payload: RaceCategoryInput) => createRaceCategory(orgId!, eventId, payload),
    onSuccess: () => {
      toast.success("Kategori lomba dibuat.");
      setCreating(false);
      setFieldErrors({});
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal membuat kategori."),
  });

  const updateMut = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<RaceCategoryInput> }) =>
      updateRaceCategory(orgId!, id, payload),
    onSuccess: () => {
      toast.success("Kategori lomba diperbarui.");
      setEditing(null);
      setFieldErrors({});
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal memperbarui kategori."),
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => deleteRaceCategory(orgId!, id),
    onSuccess: () => {
      toast.success("Kategori lomba dihapus.");
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal menghapus kategori."),
  });

  const totals = useMemo(() => {
    const items = entriesQuery.data?.items ?? [];

    return {
      total: entriesQuery.data?.meta.total ?? 0,
      // Paid, not registered: an entry sitting unpaid is holding a place, not
      // a runner who will be at the start line.
      paid: items.filter((e) => e.status === "paid").length,
      awaiting: items.filter((e) => e.awaiting_verification).length,
    };
  }, [entriesQuery.data]);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Peserta Lomba"
        description="Kategori, periode harga, dan daftar pendaftar."
        backHref={`/organizer/events/${eventId}/edit`}
        actions={
          <Button asChild variant="outline">
            <Link href={`/organizer/events/${eventId}/race-scan`}>
              <ScanLine className="h-4 w-4" />
              Check-in Peserta
            </Link>
          </Button>
        }
      />

      <RaceRouteGuard event={eventQuery.data} eventId={eventId} requires="race">
        {/* ---- Periods ---- */}
        <Card className="space-y-4 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-base font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                Periode Pendaftaran
              </h2>
              <p className="text-sm text-muted-foreground">
                Early bird, pre sale, last call — tiap periode punya harga sendiri
                per kategori.
              </p>
            </div>
            <Button size="sm" variant="outline" onClick={() => setPeriodsOpen(true)}>
              <CalendarRange className="h-4 w-4" />
              Atur Periode
            </Button>
          </div>

          {periodsQuery.isLoading ? (
            <Skeleton className="h-16 w-full" />
          ) : periods.length === 0 ? (
            <EmptyState
              icon={CalendarRange}
              title="Belum ada periode"
              description="Pendaftaran belum bisa dibuka sampai ada minimal satu periode beserta harganya."
              action={<Button onClick={() => setPeriodsOpen(true)}>Atur Periode</Button>}
            />
          ) : (
            <div className="flex flex-wrap gap-2">
              {periods.map((p) => (
                <Badge
                  key={p.id}
                  variant={p.is_current ? "success" : p.has_ended ? "neutral" : "outline"}
                >
                  {p.name}
                  {p.is_current && " · berjalan"}
                  {p.has_ended && " · selesai"}
                </Badge>
              ))}
            </div>
          )}

          {periods.length > 0 && !live && (
            <p className="text-sm text-[var(--warning)]">
              Tidak ada periode yang berjalan saat ini — pendaftaran tertutup
              sampai periode berikutnya mulai.
            </p>
          )}
        </Card>

        {/* ---- Categories ---- */}
        <Card className="space-y-4 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-base font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                Kategori
              </h2>
              <p className="text-sm text-muted-foreground">
                Mis. Mahasiswa, Umum, 5K, 10K.
              </p>
            </div>
            <Button
              size="sm"
              onClick={() => {
                setFieldErrors({});
                setCreating(true);
              }}
              disabled={periods.length === 0}
              // Disabled with a reason on screen rather than hidden: a category
              // with no window to price against cannot be saved, and the
              // server refuses it too.
              title={periods.length === 0 ? "Atur periode dulu" : undefined}
            >
              <Plus className="h-4 w-4" />
              Tambah Kategori
            </Button>
          </div>

          {catsQuery.isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : categories.length === 0 ? (
            <EmptyState
              icon={Flag}
              title="Belum ada kategori"
              description="Peserta memilih kategori saat mendaftar, jadi minimal satu kategori harus ada."
            />
          ) : (
            <div className="grid gap-3">
              {categories.map((c) => (
                <div
                  key={c.id}
                  className="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-border p-3"
                >
                  <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold">{c.name}</span>
                      {!c.is_active && <Badge variant="neutral">Nonaktif</Badge>}
                      {c.quota !== null && (
                        <Badge variant={c.remaining === 0 ? "danger" : "outline"}>
                          {c.taken}/{c.quota} terisi
                        </Badge>
                      )}
                    </div>
                    {/* The whole price list, not just today's: a runner
                        deciding when to register wants the next number, and so
                        does the organizer answering them. A window this
                        category is not sold in prints "—" rather than being
                        dropped — see the migration on why an absent row is not
                        a zero. */}
                    <div className="flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground">
                      {c.price_schedule.map((row) => (
                        <span
                          key={row.period_id}
                          className={row.is_current ? "font-semibold text-[var(--text-1)]" : undefined}
                        >
                          {row.name}: {row.price === null ? "—" : rupiah(row.price)}
                        </span>
                      ))}
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        setFieldErrors({});
                        setEditing(c);
                      }}
                    >
                      <Pencil className="h-4 w-4" />
                      Edit
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={async () => {
                        if (
                          await confirm({
                            title: `Hapus kategori "${c.name}"?`,
                            description:
                              "Kategori yang sudah ada pesertanya tidak bisa dihapus — nonaktifkan saja.",
                            confirmLabel: "Hapus",
                            tone: "danger",
                          })
                        ) {
                          deleteMut.mutate(c.id);
                        }
                      }}
                      disabled={deleteMut.isPending}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>

        {/* ---- Entries ---- */}
        <Card className="space-y-4 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-base font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                Pendaftar
              </h2>
              <p className="text-sm text-muted-foreground">
                {totals.total} pendaftar · {totals.paid} lunas
                {totals.awaiting > 0 && ` · ${totals.awaiting} menunggu verifikasi`}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari nama, email, telepon"
                className="w-full sm:w-56"
              />
              <ExportButtons
                orgId={orgId ?? undefined}
                eventId={eventId}
                kind="race-entries"
                enabled={isExportEnabled(eventQuery.data)}
              />
            </div>
          </div>

          {entriesQuery.isLoading ? (
            <Skeleton className="h-32 w-full" />
          ) : (entriesQuery.data?.items.length ?? 0) === 0 ? (
            <EmptyState
              icon={Users}
              title={search ? "Tidak ada yang cocok" : "Belum ada pendaftar"}
              description={
                search
                  ? "Coba kata kunci lain."
                  : "Pendaftar akan muncul di sini begitu mereka mengisi form publik."
              }
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-3 font-medium">Nama</th>
                    <th className="py-2 pr-3 font-medium">Kategori</th>
                    <th className="py-2 pr-3 font-medium">Periode</th>
                    <th className="py-2 pr-3 font-medium">Biaya</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    <th className="py-2 font-medium">Kontak</th>
                  </tr>
                </thead>
                <tbody>
                  {entriesQuery.data?.items.map((e) => (
                    <tr key={e.id} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 font-medium">{e.full_name}</td>
                      <td className="py-2 pr-3">{e.category?.name ?? "—"}</td>
                      {/* The snapshot, which is what was charged — never the
                          period row, which may since have been repriced. */}
                      <td className="py-2 pr-3">{e.period_name}</td>
                      <td className="py-2 pr-3">{rupiah(e.price)}</td>
                      <td className="py-2 pr-3">
                        <Badge
                          variant={
                            e.status === "paid"
                              ? "success"
                              : e.awaiting_verification
                                ? "warning"
                                : e.status === "pending"
                                  ? "outline"
                                  : "neutral"
                          }
                        >
                          {e.awaiting_verification ? "Menunggu verifikasi" : e.status}
                        </Badge>
                      </td>
                      <td className="py-2 text-muted-foreground">
                        {e.email}
                        {e.phone && ` · ${e.phone}`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </RaceRouteGuard>

      {periodsOpen && (
        <RacePeriodsDialog
          orgId={orgId!}
          eventId={eventId}
          periods={periods}
          onClose={() => setPeriodsOpen(false)}
          onSaved={() => {
            setPeriodsOpen(false);
            invalidate();
          }}
        />
      )}

      {(creating || editing) && (
        <RaceCategoryForm
          category={editing}
          periods={periods}
          fieldErrors={fieldErrors}
          pending={createMut.isPending || updateMut.isPending}
          onClose={() => {
            setCreating(false);
            setEditing(null);
            setFieldErrors({});
          }}
          onSubmit={(payload) =>
            editing
              ? updateMut.mutate({ id: editing.id, payload })
              : createMut.mutate(payload)
          }
        />
      )}
    </div>
  );
}
