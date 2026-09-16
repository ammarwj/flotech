"use client";

import { useState, type ReactNode } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { parseApiError } from "@/lib/api/errors";
import type { MatchResultGateway } from "@/lib/match-doors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { Match } from "@/types/api";

/**
 * The one scoreline row for goal-based sports: two numbers, a shootout when the
 * tie demands one, and a save whose status travels with the click.
 *
 * Extracted when the match staff got a door of their own. It was worth doing for
 * the shootout alone: "a level knockout tie needs penalties" is a rule the server
 * enforces on both doors, and a second copy of the condition on the client is a
 * screen that offers a save the API will refuse — or worse, one that quietly
 * drops the shootout and seats the wrong team in the next round.
 *
 * The other half of that rule is the status: saving a running score used to stamp
 * `finished`, which killed the LIVE badge and, for an org admin whose save
 * auto-confirms, advanced a bracket off a half-time scoreline. Hence two buttons
 * while a match is live, and `status` as a mutation argument rather than a
 * constant.
 */
export function GoalScoreEditor({
  gateway,
  match,
  knockout,
  actions,
}: {
  /** Which door this writes through — see {@link MatchResultGateway}. */
  gateway: MatchResultGateway;
  match: Match;
  /** A tie that must produce a winner: level scores go to penalties. */
  knockout: boolean;
  /** Surface-specific buttons, seated with the save controls. */
  actions?: ReactNode;
}) {
  const qc = useQueryClient();
  const [home, setHome] = useState(match.home_score?.toString() ?? "");
  const [away, setAway] = useState(match.away_score?.toString() ?? "");
  const [homePen, setHomePen] = useState(match.home_penalty?.toString() ?? "");
  const [awayPen, setAwayPen] = useState(match.away_penalty?.toString() ?? "");

  const level = home !== "" && home === away;
  const needsPenalties = knockout && level;

  const save = useMutation({
    mutationFn: (status: "ongoing" | "finished") =>
      gateway.save({
        home_score: home === "" ? null : Number(home),
        away_score: away === "" ? null : Number(away),
        home_penalty: needsPenalties && homePen !== "" ? Number(homePen) : null,
        away_penalty: needsPenalties && awayPen !== "" ? Number(awayPen) : null,
        status,
      }),
    onSuccess: (_, status) => {
      toast.success(status === "finished" ? "Hasil disimpan" : "Skor disimpan");
      for (const key of gateway.invalidate) {
        qc.invalidateQueries({ queryKey: key });
      }
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan hasil.").message),
  });

  const dirty =
    home !== (match.home_score?.toString() ?? "") ||
    away !== (match.away_score?.toString() ?? "") ||
    homePen !== (match.home_penalty?.toString() ?? "") ||
    awayPen !== (match.away_penalty?.toString() ?? "");
  const penaltiesOk =
    !needsPenalties || (homePen !== "" && awayPen !== "" && homePen !== awayPen);
  const canSave = home !== "" && away !== "" && dirty && penaltiesOk;
  // A live match splits the one button in two, and the two ask different things.
  // Saving a running score wants nothing but a change — half a scoreline is the
  // whole point. Finishing wants a complete one, but *not* a change: someone who
  // already saved the final score as ongoing still has to be able to end the
  // match.
  const live = match.status === "ongoing";
  const canFinish = home !== "" && away !== "" && penaltiesOk;

  return (
    <>
      <div className="flex flex-wrap items-center gap-3">
        <span className="flex-1 truncate text-right text-sm font-semibold">
          {match.home_team?.name ?? "TBD"}
        </span>
        <Input
          type="number"
          min={0}
          value={home}
          onChange={(e) => setHome(e.target.value)}
          className="h-9 w-14 text-center"
          aria-label={`Skor ${match.home_team?.name ?? "tim tuan rumah"}`}
        />
        <span className="text-xs text-muted-foreground">vs</span>
        <Input
          type="number"
          min={0}
          value={away}
          onChange={(e) => setAway(e.target.value)}
          className="h-9 w-14 text-center"
          aria-label={`Skor ${match.away_team?.name ?? "tim tamu"}`}
        />
        <span className="flex-1 truncate text-sm font-semibold">
          {match.away_team?.name ?? "TBD"}
        </span>
        {/* The actions travel as one block. Left loose in the wrapping row they
            break up one at a time, so a phone gets "Simpan" stranded on a line
            by itself while the scoreline keeps the other two. */}
        <div className="ml-auto flex items-center gap-3">
          {actions}
          {live ? (
            <>
              <Button
                size="sm"
                variant="outline"
                disabled={!dirty || save.isPending}
                onClick={() => save.mutate("ongoing")}
              >
                {save.isPending ? "…" : "Simpan"}
              </Button>
              <Button
                size="sm"
                variant={canFinish ? "default" : "outline"}
                disabled={!canFinish || save.isPending}
                onClick={() => save.mutate("finished")}
              >
                Selesaikan
              </Button>
            </>
          ) : (
            <Button
              size="sm"
              variant={canSave ? "default" : "outline"}
              disabled={!canSave || save.isPending}
              onClick={() => save.mutate("finished")}
            >
              {save.isPending
                ? "…"
                : match.status === "finished" && !dirty
                  ? "Tersimpan"
                  : "Simpan"}
            </Button>
          )}
        </div>
      </div>

      {needsPenalties && (
        <div className="mt-2 flex flex-wrap items-center gap-3 rounded-md border border-dashed border-border bg-[var(--surface-2)] px-3 py-2">
          <span className="text-xs font-semibold text-muted-foreground">Adu penalti</span>
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={0}
              value={homePen}
              onChange={(e) => setHomePen(e.target.value)}
              className="h-8 w-12 text-center"
              aria-label={`Penalti ${match.home_team?.name ?? "tim tuan rumah"}`}
            />
            <span className="text-xs text-muted-foreground">–</span>
            <Input
              type="number"
              min={0}
              value={awayPen}
              onChange={(e) => setAwayPen(e.target.value)}
              className="h-8 w-12 text-center"
              aria-label={`Penalti ${match.away_team?.name ?? "tim tamu"}`}
            />
          </div>
          <p className="text-xs text-muted-foreground">
            {penaltiesOk
              ? "Pemenang adu penalti yang lolos ke babak berikutnya."
              : "Skor imbang — isi hasil penalti, tidak boleh sama."}
          </p>
        </div>
      )}
    </>
  );
}
