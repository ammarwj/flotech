"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { PlayCircle, RotateCcw, Undo2, XCircle } from "lucide-react";
import { toast } from "sonner";

import { updateMatchStatus } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { useConfirm } from "@/components/shared/confirm-provider";
import { Button } from "@/components/ui/button";
import type { Match, MatchStatus } from "@/types/api";

type Settable = Exclude<MatchStatus, "finished">;

/**
 * Move a fixture between scheduled / ongoing / cancelled.
 *
 * There is deliberately **no "Selesai" button**. A match is finished by saving
 * its score, and the surest way to say so is to not offer another way.
 */
export function MatchStatusActions({
  orgId,
  eventId,
  match,
  knockout,
}: {
  orgId: string;
  eventId: string;
  match: Match;
  /** A knockout tie — cancelling a confirmed one empties the next round's slot. */
  knockout: boolean;
}) {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const mutation = useMutation({
    mutationFn: (status: Settable) => updateMatchStatus(orgId, match.id, status),
    onSuccess: (updated) => {
      toast.success(
        updated.status === "ongoing"
          ? "Pertandingan dimulai"
          : updated.status === "cancelled"
            ? "Pertandingan dibatalkan"
            : "Pertandingan dikembalikan ke terjadwal"
      );
      // Cancelling a confirmed result moves the table *and* empties a bracket
      // slot, so all three of these are stale.
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["leaderboard", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengubah status pertandingan.").message),
  });

  const cancel = async () => {
    const ok = await confirm({
      title: "Batalkan pertandingan ini?",
      description: "Pertandingan ditandai dibatalkan dan tidak dihitung di klasemen.",
      consequences: match.confirmed
        ? knockout
          ? "Tim yang sudah lolos ke babak berikutnya dikeluarkan lagi dari slotnya."
          : "Hasil yang sudah final dicabut dari klasemen."
        : undefined,
      confirmLabel: "Batalkan pertandingan",
      tone: "danger",
      icon: XCircle,
    });
    if (ok) mutation.mutate("cancelled");
  };

  const cancelBtn = (
    <Button
      size="sm"
      variant="ghost"
      disabled={mutation.isPending}
      onClick={() => void cancel()}
      className="text-muted-foreground hover:text-[var(--danger)]"
    >
      <XCircle className="h-4 w-4" />
      Batalkan
    </Button>
  );

  if (match.status === "cancelled") {
    return (
      <Button
        size="sm"
        variant="ghost"
        disabled={mutation.isPending}
        onClick={() => mutation.mutate("scheduled")}
        className="text-muted-foreground"
      >
        <RotateCcw className="h-4 w-4" />
        Aktifkan lagi
      </Button>
    );
  }

  // A finished match keeps only the escape hatch; its confirm button comes from
  // MatchConfirmBar beside this.
  if (match.status === "finished") return cancelBtn;

  return (
    <>
      {match.status === "scheduled" ? (
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate("ongoing")}
          className="text-muted-foreground"
        >
          <PlayCircle className="h-4 w-4" />
          Mulai
        </Button>
      ) : (
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate("scheduled")}
          className="text-muted-foreground"
        >
          <Undo2 className="h-4 w-4" />
          Kembali ke terjadwal
        </Button>
      )}
      {cancelBtn}
    </>
  );
}
