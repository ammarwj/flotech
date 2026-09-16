"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Send, TriangleAlert } from "lucide-react";
import { toast } from "sonner";

import {
  getTeamLineup,
  saveTeamLineup,
  submitTeamLineup,
  type SaveLineupPayload,
} from "@/lib/api/team-matches";
import { parseApiError } from "@/lib/api/errors";
import { fullDateLabel, timeOf, tzLabel } from "@/lib/match-dates";
import {
  LineupEditor,
  countRole,
  type LineupSelection,
} from "@/components/team/lineup-editor";
import { PageHeader } from "@/components/shared/page-header";
import { LineupStatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * One fixture's team sheet.
 *
 * Two buttons, and the difference between them is the whole feature: saving
 * keeps a draft the manager can keep working on, submitting hands it to the
 * referee and locks it. `editable` decides which of them is live, and it comes
 * from the server — deriving it from `status` here would make this the third
 * place that has an opinion about what `rejected` means.
 */
export default function TeamLineupPage() {
  const params = useParams<{ id: string; matchId: string }>();
  const qc = useQueryClient();

  const query = useQuery({
    queryKey: ["team-lineup", params.id, params.matchId],
    queryFn: () => getTeamLineup(params.id, params.matchId),
  });

  const data = query.data;
  const lineup = data?.lineup;
  const editable = lineup?.editable ?? false;
  const tz = data?.team.timezone ?? "Asia/Jakarta";

  const [selection, setSelection] = useState<LineupSelection>({});
  const [chosenOfficials, setChosenOfficials] = useState<string[]>([]);

  // Seed from the stored sheet. Players it does not name are left out of the
  // map, which the editor reads as "Tidak dibawa" — the same third state the
  // full-list contract means on the way back.
  useEffect(() => {
    if (!lineup) return;
    const next: LineupSelection = {};
    for (const row of lineup.players ?? []) next[row.player_id] = row.role;
    setSelection(next);
    setChosenOfficials((lineup.officials ?? []).map((row) => row.team_official_id));
  }, [lineup]);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["team-lineup", params.id, params.matchId] });
    qc.invalidateQueries({ queryKey: ["team-matches", params.id] });
  };

  /**
   * The sheet as the server wants it. Row ids are carried through from what was
   * loaded so an edit updates the row rather than replacing it — the server
   * falls back to the player id, but sending the id is what keeps the row.
   */
  const payload = (): SaveLineupPayload => {
    const playerRow = (playerId: string) =>
      (lineup?.players ?? []).find((row) => row.player_id === playerId);
    const officialRow = (officialId: string) =>
      (lineup?.officials ?? []).find((row) => row.team_official_id === officialId);

    return {
      players: (data?.roster ?? [])
        .filter((player) => selection[player.id])
        .map((player) => ({
          id: playerRow(player.id)?.id ?? null,
          player_id: player.id,
          role: selection[player.id] as "starter" | "substitute",
        })),
      officials: chosenOfficials.map((id) => ({
        id: officialRow(id)?.id ?? null,
        team_official_id: id,
      })),
    };
  };

  const save = useMutation({
    mutationFn: () => saveTeamLineup(params.id, params.matchId, payload()),
    onSuccess: () => {
      toast.success("Susunan pemain disimpan.");
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan susunan pemain.").message),
  });

  // Save first, then submit: the button the manager pressed says "kirim", and
  // handing in the sheet they are looking at rather than the one last saved is
  // what that promises.
  const submit = useMutation({
    mutationFn: async () => {
      await saveTeamLineup(params.id, params.matchId, payload());
      return submitTeamLineup(params.id, params.matchId);
    },
    onSuccess: () => {
      toast.success("Susunan pemain dikirim ke wasit.");
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengirim susunan pemain.").message),
  });

  if (query.isLoading) {
    return (
      <div className="mx-auto grid max-w-3xl gap-3">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-[320px] w-full rounded-xl" />
      </div>
    );
  }

  if (query.isError || !data || !lineup) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">Pertandingan tidak ditemukan.</p>
        <Button asChild variant="outline" className="mt-4">
          <Link href={`/participant/teams/${params.id}/matches`}>Kembali</Link>
        </Button>
      </div>
    );
  }

  const match = data.match;
  const time = timeOf(match.scheduled_at, tz);
  const starters = countRole(selection, "starter");
  const busy = save.isPending || submit.isPending;

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        title={`${match.home_team?.name ?? "TBD"} vs ${match.away_team?.name ?? "TBD"}`}
        description={
          <>
            {fullDateLabel(match.scheduled_at, tz)}
            {time && ` · ${time} ${tzLabel(tz)}`}
            {match.venue && ` · ${match.venue}`}
          </>
        }
        backHref={`/participant/teams/${params.id}/matches`}
        backLabel="Kembali ke jadwal"
        actions={<LineupStatusBadge lineup={lineup} />}
      />

      {/* Why it came back, above the form that has to answer it. */}
      {lineup.status === "rejected" && lineup.note && (
        <Card className="mb-6 border-[color-mix(in_srgb,var(--danger)_40%,transparent)]">
          <CardContent className="flex items-start gap-3 p-5">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--danger)_12%,transparent)] text-[var(--danger)]">
              <TriangleAlert className="h-5 w-5" />
            </span>
            <div>
              <h3 className="font-semibold">Ditolak wasit</h3>
              <p className="mt-0.5 text-sm text-muted-foreground">{lineup.note}</p>
            </div>
          </CardContent>
        </Card>
      )}

      {!editable && (
        <p className="mb-6 rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
          {lineup.status === "approved"
            ? "Susunan pemain sudah disetujui wasit dan tidak bisa diubah lagi."
            : "Susunan pemain sedang menunggu acc wasit. Kamu bisa mengubahnya lagi kalau wasit menolaknya."}
        </p>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Susunan Pemain</CardTitle>
          <CardDescription>
            Pilih pemain inti dan cadangan dari roster tim, lalu kirim ke wasit untuk
            disetujui.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <LineupEditor
            roster={data.roster}
            officials={data.officials}
            selection={selection}
            chosenOfficials={chosenOfficials}
            onSelectionChange={setSelection}
            onOfficialsChange={setChosenOfficials}
            sport={data.team.sport_type}
            disabled={!editable || busy}
          />
        </CardContent>
      </Card>

      {editable && (
        <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
          {/* The same rule the server enforces on submit, said before the
              button is pressed rather than as a toast afterwards. */}
          <p className="text-sm text-muted-foreground">
            {starters === 0
              ? "Pilih minimal satu pemain inti sebelum mengirim ke wasit."
              : `${starters} pemain inti dipilih.`}
          </p>
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="outline" onClick={() => save.mutate()} disabled={busy}>
              {save.isPending ? "Menyimpan…" : "Simpan draf"}
            </Button>
            <Button onClick={() => submit.mutate()} disabled={busy || starters === 0}>
              {submit.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Send className="h-4 w-4" />
              )}
              Kirim ke wasit
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
