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
 * There is deliberately **no "Selesai" button here**. A match is finished by
 * saving its score, so that button lives in the score row beside the score it
 * needs — which is also why `finished` is absent from this endpoint's
 * `Rule::in`. What this component owns is the way *back*: "Lanjutkan" returns a
 * finished match to ongoing, withdrawing its winner from the next round on the
 * way. Without it a match ended too early could only be un-done by cancelling
 * it first, three clicks through a status that means something else.
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
          ? match.status === "finished"
            ? "Pertandingan dilanjutkan"
            : "Pertandingan dimulai"
          : updated.status === "cancelled"
            ? "Pertandingan dibatalkan"
            : "Pertandingan dikembalikan ke terjadwal"
      );
      // Cancelling a confirmed result moves the table *and* empties a bracket
      // slot, so all three of these are stale.
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["leaderboard", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["discipline", orgId, eventId] });
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

  const resume = async () => {
    // Only a confirmed result has anything to take back; an unconfirmed one has
    // touched neither the table nor the bracket, so asking would be noise.
    const ok =
      !match.confirmed ||
      (await confirm({
        title: "Lanjutkan pertandingan ini?",
        description: "Pertandingan kembali berstatus berlangsung. Skornya tetap tersimpan.",
        consequences: knockout
          ? "Tim yang sudah lolos ke babak berikutnya dikeluarkan lagi dari slotnya."
          : "Hasil yang sudah final dicabut dari klasemen sampai diselesaikan lagi.",
        confirmLabel: "Lanjutkan",
        icon: PlayCircle,
      }));
    if (ok) mutation.mutate("ongoing");
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

  // A finished match keeps only the two escape hatches; its confirm button comes
  // from MatchConfirmBar beside this.
  if (match.status === "finished") {
    return (
      <>
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => void resume()}
          className="text-muted-foreground"
        >
          <PlayCircle className="h-4 w-4" />
          Lanjutkan
        </Button>
        {cancelBtn}
      </>
    );
  }

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
