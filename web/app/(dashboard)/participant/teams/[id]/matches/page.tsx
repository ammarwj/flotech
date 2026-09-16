"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { CalendarDays, ChevronRight, ClipboardList } from "lucide-react";

import { getTeamMatches } from "@/lib/api/team-matches";
import { fullDateLabel, timeOf, tzLabel } from "@/lib/match-dates";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { LineupStatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import type { MyTeamMatch } from "@/types/api";

/**
 * The manager's fixture list, and the one thing they came here to do: hand in a
 * team sheet for each one.
 *
 * The sheet's status rides along on every row rather than being fetched per
 * fixture — the server already puts it there for exactly this reason, and a
 * list that had to ask again per match would show a page of fixtures with no
 * answer to the only question being asked of it.
 */
export default function TeamMatchesPage() {
  const params = useParams<{ id: string }>();

  const query = useQuery({
    queryKey: ["team-matches", params.id],
    queryFn: () => getTeamMatches(params.id),
  });

  const data = query.data;
  const tz = data?.team.timezone ?? "Asia/Jakarta";

  if (query.isLoading) {
    return (
      <div className="mx-auto grid max-w-3xl gap-3">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-[200px] w-full rounded-xl" />
      </div>
    );
  }

  if (query.isError || !data) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">Tim tidak ditemukan.</p>
        <Button asChild variant="outline" className="mt-4">
          <Link href="/participant">Kembali</Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        title="Jadwal & Susunan Pemain"
        description={`${data.team.name}${data.team.event_name ? ` — ${data.team.event_name}` : ""}`}
        backHref={`/participant/teams/${params.id}`}
        backLabel="Kembali ke tim"
      />

      {data.matches.length === 0 ? (
        <EmptyState
          icon={CalendarDays}
          title="Belum ada jadwal"
          description="Jadwal muncul di sini setelah panitia menyusun pertandingan kategori ini."
        />
      ) : (
        <div className="grid gap-3">
          {data.matches.map((match) => (
            <MatchRow key={match.id} teamId={params.id} match={match} tz={tz} />
          ))}
        </div>
      )}
    </div>
  );
}

function MatchRow({
  teamId,
  match,
  tz,
}: {
  teamId: string;
  match: MyTeamMatch;
  tz: string;
}) {
  const time = timeOf(match.scheduled_at, tz);

  return (
    <Link href={`/participant/teams/${teamId}/matches/${match.id}/lineup`} className="block">
      <Card className="transition-colors hover:border-[var(--brand-600)]">
        <CardContent className="flex flex-wrap items-center gap-4 p-5">
          <div className="min-w-0 flex-1">
            <p className="font-semibold">
              {match.home_team?.name ?? "TBD"}
              <span className="px-2 text-muted-foreground">vs</span>
              {match.away_team?.name ?? "TBD"}
            </p>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {fullDateLabel(match.scheduled_at, tz)}
              {time && ` · ${time} ${tzLabel(tz)}`}
              {match.venue && ` · ${match.venue}`}
            </p>
          </div>

          <div className="flex items-center gap-3">
            {/* Null = the sheet row does not exist yet, which is not a fourth
                status — it is the manager never having opened the editor. */}
            {match.lineup ? (
              <LineupStatusBadge lineup={match.lineup} />
            ) : (
              <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                <ClipboardList className="h-4 w-4" />
                Belum disusun
              </span>
            )}
            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
          </div>
        </CardContent>
      </Card>
    </Link>
  );
}
