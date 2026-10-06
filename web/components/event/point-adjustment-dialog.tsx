"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { History, Plus, SlidersHorizontal, Trash2, X } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useConfirm } from "@/components/shared/confirm-provider";
import { PillTabs } from "./pill-tabs";
import { TeamCombobox } from "./team-combobox";
import {
  createAdjustment,
  deleteAdjustment,
  getAdjustments,
} from "@/lib/api/adjustments";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";

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

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
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
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Penyesuaian poin manual"
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <SlidersHorizontal className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2
              className="text-base font-bold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              Penyesuaian Poin
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Tambah atau kurangi poin di luar hasil pertandingan. Hanya poin
              dan urutan yang berubah.
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="border-b border-border px-5 pt-4">
          <PillTabs
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

        <div className="grid gap-4 overflow-y-auto p-5">
          {tab === "add" ? (
            <>
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

              <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
                <div className="grid gap-1.5">
                  <Label htmlFor="adj-points" className="font-semibold">
                    Poin<span className="text-[var(--danger)]"> *</span>
                  </Label>
                  <Input
                    id="adj-points"
                    type="number"
                    inputMode="numeric"
                    min={-99}
                    max={99}
                    placeholder="+2"
                    value={points}
                    onChange={(e) => setPoints(e.target.value)}
                  />
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
                </div>
              </div>

              <p className="text-xs text-muted-foreground">
                Alasan tampil di klasemen publik.
              </p>

              <Button
                onClick={() => add.mutate()}
                disabled={!canSave || add.isPending}
                className="justify-self-start"
              >
                {add.isPending ? "Menyimpan…" : "Tambah penyesuaian"}
              </Button>
            </>
          ) : query.isLoading ? (
            <p className="text-sm text-muted-foreground">Memuat…</p>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Belum ada penyesuaian di kategori ini.
            </p>
          ) : (
            <ul className="grid gap-2">
              {rows.map((row) => (
                <li
                  key={row.id}
                  className="flex items-start gap-3 rounded-lg border border-border p-3"
                >
                  <span
                    className="shrink-0 font-bold tabular-nums"
                    style={{
                      fontFamily: "var(--font-display)",
                      color: row.points > 0 ? "var(--success)" : "var(--danger)",
                    }}
                  >
                    {row.points > 0 ? `+${row.points}` : row.points}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold">
                      {row.team_name ?? "Tim dihapus"}
                    </p>
                    <p className="text-sm text-muted-foreground">{row.reason}</p>
                    {row.created_by_name && (
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        Dicatat {row.created_by_name}
                      </p>
                    )}
                  </div>
                  <button
                    type="button"
                    onClick={() =>
                      askRemove(
                        row.id,
                        `${row.team_name ?? "Tim"} · ${row.points > 0 ? "+" : ""}${row.points} — ${row.reason}`,
                      )
                    }
                    disabled={remove.isPending}
                    aria-label="Hapus penyesuaian"
                    className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-[var(--danger)] disabled:opacity-50"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}

function FieldError({ error }: { error?: string }) {
  if (!error) return null;

  return <p className="text-xs text-[var(--danger)]">{error}</p>;
}
