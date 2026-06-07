"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Clock } from "lucide-react";
import { toast } from "sonner";

import { confirmResult } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import type { Match } from "@/types/api";

/**
 * Confirm / unconfirm a match result. A result only counts toward standings and
 * bracket progression once confirmed.
 */
export function MatchConfirmBar({
  orgId,
  eventId,
  match,
}: {
  orgId: string;
  eventId: string;
  match: Match;
}) {
  const qc = useQueryClient();

  const mutation = useMutation({
    mutationFn: (confirmed: boolean) => confirmResult(orgId, match.id, confirmed),
    onSuccess: (_, confirmed) => {
      toast.success(confirmed ? "Hasil dikonfirmasi" : "Konfirmasi dibatalkan");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["leaderboard", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memperbarui konfirmasi.").message),
  });

  if (match.status !== "finished") return null;

  if (match.confirmed) {
    return (
      <div className="flex items-center gap-3 text-xs">
        <span className="inline-flex items-center gap-1 font-medium text-[var(--success)]">
          <CheckCircle2 className="h-3.5 w-3.5" />
          Hasil final
        </span>
        <button
          type="button"
          onClick={() => mutation.mutate(false)}
          disabled={mutation.isPending}
          className="text-muted-foreground underline hover:text-foreground disabled:opacity-50"
        >
          Batalkan
        </button>
      </div>
    );
  }

  return (
    <div className="flex items-center gap-2">
      <span className="inline-flex items-center gap-1 text-xs text-[var(--warning)]">
        <Clock className="h-3.5 w-3.5" />
        Menunggu konfirmasi
      </span>
      <Button size="sm" variant="outline" onClick={() => mutation.mutate(true)} disabled={mutation.isPending}>
        <CheckCircle2 className="h-3.5 w-3.5" />
        Konfirmasi
      </Button>
    </div>
  );
}
