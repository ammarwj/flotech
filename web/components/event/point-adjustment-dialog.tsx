"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { History, Plus, SlidersHorizontal, Trash2, X } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  dialogBodyClass,
  dialogCloseClass,
  dialogDescriptionClass,
  dialogFooterClass,
  dialogHeaderRow,
  dialogIconChip,
  dialogOverlay,
  dialogPanel,
  dialogTitleClass,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { useConfirm } from "@/components/shared/confirm-provider";
import { PillTabs } from "./pill-tabs";
import { TeamCombobox } from "./team-combobox";
import {
  createAdjustment,
  deleteAdjustment,
  getAdjustments,
} from "@/lib/api/adjustments";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { cn } from "@/lib/utils";
import type { StandingAdjustment } from "@/types/api";

/**
 * The category's manual point ledger — house rules the fixtures cannot express
 * ("suporter datang lengkap = +2 poin, sebagian = +1"), or a sanction docking a
 * squad that walked off.
 *
 * Entries are appended and removed, never edited: a signed number plus a reason
 * plus an author is a statement somebody made, and editing it in place would
 * leave the original author's name on a statement they never made. Correcting
 * one means deleting it and typing it again. The copy says so, because "no edit
 * button" otherwise reads as a missing feature.
 */
interface PointAdjustmentDialogProps {
  orgId: string;
  eventId: string;
  categoryId: string;
  /** Seeds the picker when opened from a row, rather than from the toolbar. */
  teamId?: string | null;
  onClose: () => void;
}

/**
 * `min-h-0` is load-bearing. The panel is a flex column and this is the only
 * pane meant to scroll, but a flex child's default `min-height: auto` refuses
 * to shrink below its content — so a long ledger grew the pane instead of
 * scrolling it, and the panel's own `overflow-hidden` then clipped the last
 * rows away with no way to reach them. `p-4` on a phone to buy back a gutter.
 */
const bodyClass = cn(dialogBodyClass, "min-h-0 flex-1 p-4 sm:p-5");

/** The house rules organizers actually type, so nobody fights a number input. */
const PRESETS = [1, 2, 3, -1, -3];

const signed = (n: number) => (n > 0 ? `+${n}` : `${n}`);

export function PointAdjustmentDialog({
  open,
  ...props
}: PointAdjustmentDialogProps & { open: boolean }) {
  // Mounted only while open, so every open starts from an empty form.
  return open ? <Dialog {...props} /> : null;
}

function Dialog({
  orgId,
  eventId,
  categoryId,
  teamId = null,
  onClose,
}: PointAdjustmentDialogProps) {
  const qc = useQueryClient();
  const confirm = useConfirm();

  // Two panes, not one scroll: the form is a thing you fill in, the ledger is a
  // thing you read, and stacking them put the submit button in the middle of a
  // list of entries. Opens on the form — the dialog is reached from a button
  // that says "add".
  const [tab, setTab] = useState<"add" | "history">("add");

  const [team, setTeam] = useState(teamId ?? "");
  // Kept as a string: an empty number input is not 0, and "-" is a legitimate
  // intermediate state while someone types a sanction.
  const [points, setPoints] = useState("");
  const [reason, setReason] = useState("");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  // Escape and the scroll lock, the pair every hand-rolled dialog here carries
  // (match-detail-dialog does the same). The lock matters most as a sheet: a
  // drag that runs off the end of the ledger would otherwise scroll the
  // schedule page underneath it.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [onClose]);

  const query = useQuery({
    queryKey: ["adjustments", orgId, eventId, categoryId],
    queryFn: () => getAdjustments(orgId, eventId, categoryId),
  });

  // Both keys: the ledger list is what this dialog shows, the table behind it
  // is what the entry was typed for.
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["adjustments", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
  };

  const add = useMutation({
    mutationFn: () =>
      createAdjustment(orgId, eventId, categoryId, {
        team_id: team,
        points: Number(points),
        reason: reason.trim(),
      }),
    onSuccess: () => {
      toast.success("Penyesuaian poin ditambahkan");
      setPoints("");
      setReason("");
      setFieldErrors({});
      refresh();
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err);
      setFieldErrors(fieldErrors);
      toast.error(message);
    },
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteAdjustment(orgId, id),
    onSuccess: () => {
      toast.success("Penyesuaian poin dihapus");
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const askRemove = async (id: string, label: string) => {
    const ok = await confirm({
      title: "Hapus penyesuaian ini?",
      description: label,
      consequences:
        "Poin tim langsung berubah. Salah angka? Hapus lalu ketik ulang — entri tidak bisa diedit.",
      confirmLabel: "Hapus",
      tone: "danger",
      icon: Trash2,
    });

    if (ok) remove.mutate(id);
  };

  const parsed = Number(points);
  const canSave =
    team !== "" &&
    points.trim() !== "" &&
    Number.isInteger(parsed) &&
    parsed !== 0 &&
    reason.trim() !== "";

  const rows = query.data ?? [];

  return (
    // Hand-rolled rather than ui/dialog's Radix shell, same as the sibling
    // manual-match dialog: a modal Radix dialog puts `pointer-events: none` on
    // <body>, and TeamCombobox portals its results list there — the organizer
    // would see the list and be unable to click a team. The class strings are
    // still imported from ui/dialog so the two shells cannot drift apart.
    //
    // A bottom sheet on a phone, a centred card from sm up: the ledger is a
    // list you scroll, which is what a sheet is for, and the tab strip plus
    // footer leave a centred card almost no room for rows on a short screen.
    <div className={dialogOverlay.sheet} data-state="open" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Penyesuaian poin manual"
        onClick={(e) => e.stopPropagation()}
        // h-[85vh] on a phone, not just max-h: a sheet that resizes between the
        // two tabs jumps under the thumb, and a short ledger would put the
        // footer halfway up the screen.
        // data-state is what drives the slide-up/zoom keyframes in globals.css.
        // Only ever "open": the component unmounts on close, so there is no
        // exit animation to wait for.
        data-state="open"
        className={cn(dialogPanel.sheet, "h-[85vh] sm:h-auto")}
      >
        <div className={cn(dialogHeaderRow, "shrink-0 p-4 sm:p-5")}>
          <span className={dialogIconChip.default}>
            <SlidersHorizontal className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className={dialogTitleClass} style={{ fontFamily: "var(--font-display)" }}>
              Penyesuaian poin
            </h2>
            <p className={dialogDescriptionClass}>
              Tambah atau kurangi poin di luar hasil pertandingan. Hanya poin
              dan urutan klasemen yang berubah.
            </p>
          </div>
          <button onClick={onClose} className={dialogCloseClass} aria-label="Tutup">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="shrink-0 px-4 pt-4 sm:px-5">
          <PillTabs
            tone="tint"
            items={[
              { key: "add", label: "Tambah", icon: Plus },
              {
                key: "history",
                label: rows.length ? `Riwayat (${rows.length})` : "Riwayat",
                icon: History,
              },
            ]}
            activeKey={tab}
            onSelect={(key) => setTab(key as "add" | "history")}
          />
        </div>

        {tab === "add" ? (
          <div className={bodyClass}>
            <div className="grid gap-1.5">
              <Label htmlFor="adj-team" className="font-semibold">
                Tim<span className="text-[var(--danger)]"> *</span>
              </Label>
              <TeamCombobox
                id="adj-team"
                orgId={orgId}
                eventId={eventId}
                categoryId={categoryId}
                value={team}
                onChange={setTeam}
              />
              <FieldError error={fieldErrors.team_id} />
            </div>

            <div className="grid gap-1.5">
              <Label htmlFor="adj-points" className="font-semibold">
                Poin<span className="text-[var(--danger)]"> *</span>
              </Label>
              {/* Input and presets as two rows on a phone: side by side the
                  five chips wrap to a ragged second line beside a field that
                  keeps its own. */}
              <div className="flex items-center gap-2">
                <Input
                  id="adj-points"
                  type="number"
                  inputMode="numeric"
                  min={-99}
                  max={99}
                  placeholder="+2"
                  value={points}
                  onChange={(e) => setPoints(e.target.value)}
                  className="w-20 shrink-0 text-center font-bold tabular-nums"
                  style={{ fontFamily: "var(--font-display)" }}
                />
                {/* The numbers organizers reach for, so a sanction does not
                    depend on typing "-" into a number field. */}
                <div className="flex flex-1 gap-1.5">
                  {PRESETS.map((n) => {
                    const on = parsed === n && points.trim() !== "";
                    return (
                      <button
                        key={n}
                        type="button"
                        onClick={() => setPoints(String(n))}
                        aria-pressed={on}
                        className={cn(
                          "h-10 flex-1 rounded-md border text-sm font-bold tabular-nums transition-colors",
                          on
                            ? "border-[var(--brand-600)] bg-[var(--tint)] text-[var(--brand-600)]"
                            : "border-border text-muted-foreground hover:bg-accent hover:text-foreground",
                        )}
                      >
                        {signed(n)}
                      </button>
                    );
                  })}
                </div>
              </div>
              <FieldError error={fieldErrors.points} />
            </div>

            <div className="grid gap-1.5">
              <Label htmlFor="adj-reason" className="font-semibold">
                Alasan<span className="text-[var(--danger)]"> *</span>
              </Label>
              <Input
                id="adj-reason"
                placeholder="Suporter lengkap matchday 3"
                maxLength={255}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
              <FieldError error={fieldErrors.reason} />
              <p className="text-xs text-muted-foreground">
                Tampil di klasemen publik, jadi tulis alasan yang bisa dibaca
                peserta.
              </p>
            </div>
          </div>
        ) : (
          <div className={bodyClass}>
            {query.isLoading ? (
              <p className="text-sm text-muted-foreground">Memuat…</p>
            ) : rows.length === 0 ? (
              <EmptyState
                icon={History}
                title="Belum ada penyesuaian"
                description="Entri yang kamu tambahkan muncul di sini, lengkap dengan nama pencatat dan tanggalnya."
                className="px-5 py-10"
              />
            ) : (
              <>
                {/* One bordered sheet with ruled rows, not a stack of cards:
                    the signed numbers are a column to be read down. */}
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                  {rows.map((row) => (
                    <LedgerRow
                      key={row.id}
                      row={row}
                      busy={remove.isPending}
                      onRemove={() =>
                        askRemove(
                          row.id,
                          `${row.team_name ?? "Tim"} ${signed(row.points)} — ${row.reason}`,
                        )
                      }
                    />
                  ))}
                </ul>

                {/* Said here and not in the header: before the first entry
                    there is nothing to correct, and no total is printed —
                    summing points across different teams answers nothing. */}
                <p className="text-xs text-muted-foreground">
                  Entri tidak bisa diedit. Salah angka? Hapus lalu ketik ulang.
                </p>
              </>
            )}
          </div>
        )}

        <div className={cn(dialogFooterClass, "shrink-0")}>
          <Button variant="secondary" onClick={onClose}>
            Tutup
          </Button>
          {tab === "add" && (
            <Button onClick={() => add.mutate()} disabled={!canSave || add.isPending}>
              {add.isPending ? "Menyimpan…" : "Tambah penyesuaian"}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

function LedgerRow({
  row,
  busy,
  onRemove,
}: {
  row: StandingAdjustment;
  busy: boolean;
  onRemove: () => void;
}) {
  const positive = row.points > 0;

  return (
    <li className="flex items-start gap-3 p-3">
      <span
        className="grid h-9 w-11 shrink-0 place-items-center rounded-md text-sm font-bold tabular-nums"
        style={{
          fontFamily: "var(--font-display)",
          color: positive ? "var(--success)" : "var(--danger)",
          background: `color-mix(in srgb, ${
            positive ? "var(--success)" : "var(--danger)"
          } 10%, transparent)`,
        }}
      >
        {signed(row.points)}
      </span>

      {/* break-words, not truncate: a reason is the whole point of the entry,
          and a team name typed by a participant can be long enough to overflow
          a phone on its own. */}
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold break-words">
          {row.team_name ?? "Tim dihapus"}
        </p>
        <p className="text-sm text-muted-foreground break-words">{row.reason}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {row.created_by_name
            ? `Dicatat ${row.created_by_name}, ${shortDate(row.created_at)}`
            : `Dicatat ${shortDate(row.created_at)}`}
        </p>
      </div>

      <button
        type="button"
        onClick={onRemove}
        disabled={busy}
        aria-label={`Hapus penyesuaian ${signed(row.points)} untuk ${row.team_name ?? "tim"}`}
        className="-m-1 grid h-10 w-10 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-[var(--danger)] disabled:opacity-50"
      >
        <Trash2 className="h-4 w-4" />
      </button>
    </li>
  );
}

const shortDate = (iso: string) =>
  new Date(iso).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });

function FieldError({ error }: { error?: string }) {
  if (!error) return null;

  return <p className="text-xs text-[var(--danger)]">{error}</p>;
}
