"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2 } from "lucide-react";
import { toast } from "sonner";

import { confirmResult } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import type { Match } from "@/types/api";

/**
 * The confirm / unconfirm button for a match result. A result only counts toward
 * standings and bracket progression once confirmed.
 *
 * It states nothing — MatchStatusBadge says whether a result is final or still
 * waiting. This is only the action.
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
  const { isOrgAdmin } = useActiveOrg();

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

  // Mirrors GameMatch::isFinished() — status alone isn't enough. A walkover is
  // finished with no scores, and confirmResult() 422s on those, so offering the
  // button there would be offering a guaranteed error.
  if (match.status !== "finished" || match.home_score === null || match.away_score === null) {
    return null;
  }

  // Signing a result off belongs to whoever runs the org; the endpoint is
  // behind org.admin, so for an operator this would be a 403 waiting to happen.
  // They still see the badge saying the result is pending.
  if (!isOrgAdmin) return null;

  if (match.confirmed) {
    return (
      <Button
        size="sm"
        variant="ghost"
        onClick={() => mutation.mutate(false)}
        disabled={mutation.isPending}
        className="text-muted-foreground"
      >
        {/* "konfirmasi", not a bare "Batalkan": this sits beside a "Batalkan
            pertandingan" action that means something else entirely. */}
        Batalkan konfirmasi
      </Button>
    );
  }

  return (
    <Button size="sm" variant="outline" onClick={() => mutation.mutate(true)} disabled={mutation.isPending}>
      <CheckCircle2 className="h-3.5 w-3.5" />
      Konfirmasi
    </Button>
  );
}
