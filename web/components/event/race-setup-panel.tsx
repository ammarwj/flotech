"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CalendarRange, Flag, Pencil, Plus, Trash2 } from "lucide-react";

import {
  createRaceCategory,
  deleteRaceCategory,
  getRaceCategories,
  getRacePeriods,
  updateRaceCategory,
  type RaceCategoryInput,
} from "@/lib/api/race";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { currentPeriod } from "@/lib/race";
import { rupiah } from "@/lib/labels";
import { useConfirm } from "@/components/shared/confirm-provider";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/event/section-header";
import { RacePeriodsDialog } from "@/components/event/race-periods-dialog";
import { RaceCategoryForm } from "@/components/event/race-category-form";
import type { RaceCategory } from "@/types/api";

/**
 * A fun run's sale windows and the categories priced against them, on the page
 * where the rest of the event is set up.
 *
 * **These used to live on the entrants page, and that was the wrong place.**
 * Setting up what an event sells is the same job as naming it and choosing its
 * dates; the entrants page answers "who signed up", which is a different
 * question asked on a different day. An organizer looking for the price list
 * had to work out that it lived behind a button labelled with the name of the
 * people who bought from it. A panel on the edit page is the precedent
 * EventPlanPanel already set.
 *
 * The two stay in ONE component rather than two, for the reason they were
 * always rendered together: a price belongs to a window, so a category cannot
 * be saved before a window exists — the add button is disabled with that reason
 * on screen, and the server refuses it too. Split apart, that rule would have
 * to be passed between them as a prop.
 *
 * Only for a race. The caller gates it; `event_categories` is a different table
 * with its own editor inside EventForm.
 */
export function RaceSetupPanel({
  orgId,
  eventId,
}: {
  orgId: string;
  eventId: string;
}) {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const [editing, setEditing] = useState<RaceCategory | null>(null);
  const [creating, setCreating] = useState(false);
  const [periodsOpen, setPeriodsOpen] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const periodsQuery = useQuery({
    queryKey: ["race-periods", orgId, eventId],
    queryFn: () => getRacePeriods(orgId, eventId),
  });

  const catsQuery = useQuery({
    queryKey: ["race-categories", orgId, eventId],
    queryFn: () => getRaceCategories(orgId, eventId),
  });

  const periods = periodsQuery.data ?? [];
  const categories = catsQuery.data ?? [];
  const live = currentPeriod(periods);

  /**
   * Editing a window changes what every category costs, so the categories have
   * to be asked again — the price schedule they publish is joined server-side.
   * The entrants list goes with them because a category rename shows up on
   * every row there.
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
    mutationFn: (payload: RaceCategoryInput) =>
      createRaceCategory(orgId, eventId, payload),
    onSuccess: () => {
      toast.success("Kategori dibuat.");
      setCreating(false);
      setFieldErrors({});
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal membuat kategori."),
  });

  const updateMut = useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Partial<RaceCategoryInput>;
    }) => updateRaceCategory(orgId, id, payload),
    onSuccess: () => {
      toast.success("Kategori diperbarui.");
      setEditing(null);
      setFieldErrors({});
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal memperbarui kategori."),
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => deleteRaceCategory(orgId, id),
    onSuccess: () => {
      toast.success("Kategori dihapus.");
      invalidate();
    },
    onError: (err) => handleError(err, "Gagal menghapus kategori."),
  });

  return (
    <>
      <Card>
        <SectionHeader
          icon={CalendarRange}
          title="Periode Pendaftaran"
          description="Early bird, pre sale, last call — tiap periode punya harga sendiri per kategori."
          action={
            <Button size="sm" variant="outline" onClick={() => setPeriodsOpen(true)}>
              <CalendarRange className="h-4 w-4" />
              Atur Periode
            </Button>
          }
        />
        <CardContent className="space-y-3">
          {periodsQuery.isLoading ? (
            <Skeleton className="h-16 w-full" />
          ) : periods.length === 0 ? (
            <EmptyState
              icon={CalendarRange}
              title="Belum ada periode"
              description="Pendaftaran belum bisa dibuka sampai ada minimal satu periode beserta harganya."
              action={
                <Button onClick={() => setPeriodsOpen(true)}>
                  Atur Periode
                </Button>
              }
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
        </CardContent>
      </Card>

      <Card>
        <SectionHeader
          icon={Flag}
          title="Kategori Lomba"
          description="Mis. Mahasiswa, Umum, 5K, 10K. Harganya diisi per periode."
          action={
            <Button
              size="sm"
              onClick={() => {
                setFieldErrors({});
                setCreating(true);
              }}
              disabled={periods.length === 0}
              // Disabled with the reason on screen rather than hidden: a
              // category with no window to price against cannot be saved, and
              // the server refuses it too.
              title={periods.length === 0 ? "Atur periode dulu" : undefined}
            >
              <Plus className="h-4 w-4" />
              Tambah Kategori
            </Button>
          }
        />
        <CardContent>
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
                          className={
                            row.is_current ? "font-semibold text-[var(--text-1)]" : undefined
                          }
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
        </CardContent>
      </Card>

      {periodsOpen && (
        <RacePeriodsDialog
          orgId={orgId}
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
    </>
  );
}
