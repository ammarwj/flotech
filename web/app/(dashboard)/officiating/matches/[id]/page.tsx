"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Shirt, Undo2, UserCog } from "lucide-react";
import { toast } from "sonner";

import {
  approveLineup,
  getMatchLineups,
  rejectLineup,
} from "@/lib/api/officiating";
import { parseApiError } from "@/lib/api/errors";
import { fullDateLabel, timeOf, tzLabel } from "@/lib/match-dates";
import { PageHeader } from "@/components/shared/page-header";
import { LineupStatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import type { MatchLineup } from "@/types/api";

/**
 * The referee's verdict on one fixture's two team sheets.
 *
 * Wrapped for the same reason the crew's schedule is: the event id travels in
 * the query string, and useSearchParams() needs a Suspense boundary above it to
 * build.
 */
export default function OfficiatingMatchPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full rounded-xl" />}>
      <OfficiatingMatchView />
    </Suspense>
  );
}

/**
 * Why the event id is a query parameter rather than a path segment: every
 * officiating endpoint is scoped `officiating/events/{event}/…`, because the
 * personnel row — which is what proves this account may be here at all — is
 * per-event. A `/officiating/matches/{id}` route with no event in it would have
 * to look the event up from the match, and the only door that answers that is
 * the organizer's, which a task account is deliberately locked out of.
 */
function OfficiatingMatchView() {
  const { id: matchId } = useParams<{ id: string }>();
  const eventId = useSearchParams().get("event") ?? "";

  const query = useQuery({
    queryKey: ["officiating-lineups", eventId, matchId],
    queryFn: () => getMatchLineups(eventId, matchId),
    enabled: !!eventId,
  });

  const data = query.data;
  const tz = data?.event.timezone ?? "Asia/Jakarta";
  const match = data?.match;
  const time = timeOf(match?.scheduled_at ?? null, tz);

  if (query.isLoading) {
    return (
      <div className="grid gap-3">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-[320px] w-full rounded-xl" />
      </div>
    );
  }

  if (!eventId || query.isError || !data || !match) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">Pertandingan tidak ditemukan.</p>
        <Button asChild variant="outline" className="mt-4">
          <Link
            href={eventId ? `/officiating/events/${eventId}` : "/officiating"}
          >
            Kembali
          </Link>
        </Button>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={`${match.home_team?.name ?? "TBD"} vs ${match.away_team?.name ?? "TBD"}`}
        description={
          <>
            {fullDateLabel(match.scheduled_at, tz)}
            {time && ` · ${time} ${tzLabel(tz)}`}
            {match.venue && ` · ${match.venue}`}
          </>
        }
        backHref={`/officiating/events/${eventId}`}
        backLabel="Kembali ke jadwal"
      />

      <p className="mb-6 rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
        Setiap tim di-acc sendiri-sendiri. Susunan yang sudah disetujui tidak
        bisa diubah lagi — baik olehmu maupun oleh manajer tim.
      </p>

      {/* grid-cols-1 is load-bearing on phones, same as the schedule: an
          implicit `auto` track is sized by its widest child's min-content, and
          a roster row that refuses to shrink drags the page wider than the
          viewport. */}
      <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
        <TeamSheetCard
          teamName={match.home_team?.name ?? "TBD"}
          side="Tuan rumah"
          lineup={data.home}
          eventId={eventId}
          matchId={matchId}
        />
        <TeamSheetCard
          teamName={match.away_team?.name ?? "TBD"}
          side="Tim tamu"
          lineup={data.away}
          eventId={eventId}
          matchId={matchId}
        />
      </div>
    </div>
  );
}

/**
 * One team's sheet, and the two answers it can be given.
 *
 * A null lineup is not a fifth status: the row is created when the manager first
 * opens the editor, so its absence means nobody has started, and the card says
 * which team is still being waited on rather than rendering an empty sheet that
 * looks ready to sign.
 */
function TeamSheetCard({
  teamName,
  side,
  lineup,
  eventId,
  matchId,
}: {
  teamName: string;
  /** "Tuan rumah" / "Tim tamu" — which half of the fixture this card is. */
  side: string;
  lineup: MatchLineup | null;
  eventId: string;
  matchId: string;
}) {
  const qc = useQueryClient();
  const [note, setNote] = useState("");
  const [rejecting, setRejecting] = useState(false);
  const [noteError, setNoteError] = useState<string | null>(null);

  const refresh = () =>
    qc.invalidateQueries({
      queryKey: ["officiating-lineups", eventId, matchId],
    });

  const approve = useMutation({
    mutationFn: () => approveLineup(eventId, lineup!.id),
    onSuccess: () => {
      toast.success(`Susunan pemain ${teamName} disetujui.`);
      refresh();
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menyetujui susunan.").message),
  });

  const reject = useMutation({
    mutationFn: () => rejectLineup(eventId, lineup!.id, note),
    onSuccess: () => {
      toast.success(`Susunan pemain ${teamName} dikembalikan ke manajer.`);
      setNote("");
      setRejecting(false);
      setNoteError(null);
      refresh();
    },
    // The reason is a field the referee typed, so its 422 belongs under the
    // textarea; anything else is a banner. Same split as every other form here.
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menolak susunan.");
      if (parsed.fieldErrors.note) setNoteError(parsed.fieldErrors.note);
      else toast.error(parsed.message);
    },
  });

  const starters = (lineup?.players ?? []).filter((p) => p.role === "starter");
  const substitutes = (lineup?.players ?? []).filter(
    (p) => p.role === "substitute",
  );
  const busy = approve.isPending || reject.isPending;

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {side}
          </p>
          <CardTitle className="truncate">{teamName}</CardTitle>
        </div>
        {lineup && <LineupStatusBadge lineup={lineup} />}
      </CardHeader>

      <CardContent className="grid gap-5">
        {!lineup ? (
          <p className="rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
            Manajer tim ini belum menyusun pemain.
          </p>
        ) : (
          <>
            {lineup.status === "draft" && (
              <p className="rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
                Masih draf manajer, belum dikirim untuk di-acc.
              </p>
            )}

            {lineup.status === "rejected" && lineup.note && (
              <p className="rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
                Dikembalikan dengan alasan: {lineup.note}
              </p>
            )}

            <PlayerList title="Pemain inti" rows={starters} />
            <PlayerList title="Cadangan" rows={substitutes} />

            <section className="grid gap-2">
              <h3 className="inline-flex items-center gap-2 text-sm font-semibold">
                <UserCog className="h-4 w-4" /> Ofisial di bangku
              </h3>
              {(lineup.officials ?? []).length === 0 ? (
                <p className="text-sm text-muted-foreground">Tidak ada.</p>
              ) : (
                <ul className="grid gap-1.5">
                  {(lineup.officials ?? []).map((official) => (
                    <li
                      key={official.id}
                      className="flex items-center justify-between gap-3 text-sm"
                    >
                      <span className="truncate font-medium">
                        {official.full_name}
                      </span>
                      {/* role_display is resolved by the server from the sport's
                          catalogue — an admin may rename a role, and no screen
                          keeps a second copy of those labels. */}
                      <span className="shrink-0 text-muted-foreground">
                        {official.role_display}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            {lineup.status === "submitted" && (
              <div className="grid gap-3 border-t border-border pt-4">
                {rejecting ? (
                  <div className="grid gap-2">
                    <label
                      htmlFor={`note-${lineup.id}`}
                      className="text-sm font-medium"
                    >
                      Alasan dikembalikan
                    </label>
                    <Textarea
                      id={`note-${lineup.id}`}
                      value={note}
                      maxLength={500}
                      placeholder="Contoh: nomor punggung ganda, pemain tidak ada di roster."
                      onChange={(e) => {
                        setNote(e.target.value);
                        setNoteError(null);
                      }}
                      disabled={busy}
                    />
                    {noteError && (
                      <p className="text-sm text-[var(--danger)]">
                        {noteError}
                      </p>
                    )}
                    <div className="flex flex-wrap justify-end gap-2">
                      <Button
                        variant="ghost"
                        onClick={() => {
                          setRejecting(false);
                          setNoteError(null);
                        }}
                        disabled={busy}
                      >
                        Batal
                      </Button>
                      <Button
                        variant="destructive"
                        onClick={() => reject.mutate()}
                        disabled={busy || note.trim() === ""}
                      >
                        {reject.isPending
                          ? "Mengirim…"
                          : "Kembalikan ke manajer"}
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button
                      variant="outline"
                      onClick={() => setRejecting(true)}
                      disabled={busy}
                    >
                      <Undo2 className="h-4 w-4" />
                      Tolak
                    </Button>
                    <Button onClick={() => approve.mutate()} disabled={busy}>
                      <Check className="h-4 w-4" />
                      {approve.isPending ? "Menyetujui…" : "Setujui"}
                    </Button>
                  </div>
                )}
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** Starters and substitutes print as separate blocks, not one list in two colours. */
function PlayerList({
  title,
  rows,
}: {
  title: string;
  rows: NonNullable<MatchLineup["players"]>;
}) {
  return (
    <section className="grid gap-2">
      <h3 className="inline-flex items-center gap-2 text-sm font-semibold">
        <Shirt className="h-4 w-4" /> {title}
        <span className="font-normal text-muted-foreground">
          ({rows.length})
        </span>
      </h3>
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">Tidak ada.</p>
      ) : (
        <ul className="grid gap-1.5">
          {rows.map((row) => (
            <li key={row.id} className="flex items-center gap-3 text-sm">
              <span
                className="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-xs font-bold text-[var(--brand-600)]"
                style={{ fontFamily: "var(--font-display)" }}
              >
                {row.jersey_number || "–"}
              </span>
              <span className="min-w-0 flex-1 truncate font-medium">
                {row.full_name}
              </span>
              {row.position && (
                <span className="shrink-0 text-muted-foreground">
                  {row.position}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
