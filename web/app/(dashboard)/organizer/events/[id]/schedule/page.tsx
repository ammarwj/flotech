"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarClock, ListOrdered, Network, Sparkles, Goal, LayoutList, CalendarRange } from "lucide-react";
import { toast } from "sonner";

import {
  getMatches,
  getStandings,
  getLeaderboard,
  generateSchedule,
  updateMatchResult,
} from "@/lib/api/matches";
import { getEvent } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { knockoutRoundLabel, groupByRound } from "@/lib/bracket";
import { isSetBased } from "@/lib/scoring";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { StandingsTable } from "@/components/event/standings-table";
import { BracketView } from "@/components/event/bracket-view";
import { LeaderboardTable } from "@/components/event/leaderboard-table";
import { MatchStatsEditor } from "@/components/event/match-stats-editor";
import { SetScoreEditor } from "@/components/event/set-score-editor";
import { MatchCalendar } from "@/components/event/match-calendar";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

type Tab = "schedule" | "standings" | "bracket" | "stats";

export default function SchedulePage() {
  const { orgId } = useActiveOrg();
  const { id: eventId } = useParams<{ id: string }>();
  const [tab, setTab] = useState<Tab | null>(null);
  const [scheduleView, setScheduleView] = useState<"list" | "calendar">("list");

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });
  const matchesQuery = useQuery({
    queryKey: ["matches", orgId, eventId],
    queryFn: () => getMatches(orgId!, eventId),
    enabled: !!orgId,
  });
  const standingsQuery = useQuery({
    queryKey: ["standings", orgId, eventId],
    queryFn: () => getStandings(orgId!, eventId),
    enabled: !!orgId,
  });
  const leaderboardQuery = useQuery({
    queryKey: ["leaderboard", orgId, eventId],
    queryFn: () => getLeaderboard(orgId!, eventId),
    enabled: !!orgId,
  });

  const qc = useQueryClient();
  const matches = matchesQuery.data ?? [];
  const isKnockout = eventQuery.data?.tournament_format === "knockout_single";
  const setBased = eventQuery.data ? isSetBased(eventQuery.data.sport_type) : false;
  const activeTab: Tab = tab ?? (isKnockout ? "bracket" : "schedule");

  const generate = useMutation({
    mutationFn: () => generateSchedule(orgId!, eventId),
    onSuccess: (created) => {
      toast.success(`Jadwal dibuat: ${created.length} pertandingan`);
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat jadwal.").message),
  });

  const handleGenerate = () => {
    if (matches.length > 0 && !confirm("Buat ulang jadwal akan menghapus jadwal & hasil yang ada. Lanjutkan?")) {
      return;
    }
    generate.mutate();
  };

  const rounds = groupByRound(matches);
  const tabs: [Tab, string, typeof CalendarClock][] = isKnockout
    ? [
        ["bracket", "Bracket", Network],
        ["schedule", "Jadwal & Hasil", CalendarClock],
        ["stats", "Statistik", Goal],
      ]
    : [
        ["schedule", "Jadwal", CalendarClock],
        ["standings", "Klasemen", ListOrdered],
        ["stats", "Statistik", Goal],
      ];

  return (
    <div>
      <PageHeader
        title="Jadwal & Klasemen"
        description={eventQuery.data?.name ?? "Atur pertandingan, input hasil, dan pantau klasemen."}
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
        actions={
          <Button onClick={handleGenerate} disabled={generate.isPending || !orgId}>
            <Sparkles className="h-4 w-4" />
            {generate.isPending ? "Membuat…" : matches.length > 0 ? "Buat Ulang Jadwal" : "Buat Jadwal"}
          </Button>
        }
      />

      <div className="mb-6 inline-flex items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
        {tabs.map(([key, label, Icon]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={cn(
              "inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 transition-colors",
              activeTab === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
            )}
          >
            <Icon className="h-4 w-4" />
            {label}
          </button>
        ))}
      </div>

      {matchesQuery.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-16 w-full rounded-xl" />
          ))}
        </div>
      ) : matches.length === 0 ? (
        <EmptyState
          icon={CalendarClock}
          title="Belum ada jadwal"
          description={
            isKnockout
              ? "Buat bracket knockout otomatis dari tim yang sudah disetujui. Butuh minimal 2 tim."
              : "Buat jadwal round-robin otomatis dari tim yang sudah disetujui. Butuh minimal 2 tim."
          }
          action={
            <Button onClick={handleGenerate} disabled={generate.isPending}>
              <Sparkles className="h-4 w-4" />
              Buat Jadwal
            </Button>
          }
        />
      ) : activeTab === "bracket" ? (
        <div className="overflow-x-auto pb-2">
          <BracketView matches={matches} />
        </div>
      ) : activeTab === "schedule" ? (
        <div className="max-w-3xl">
          <div className="mb-4 inline-flex items-center gap-1 rounded-lg border border-border bg-[var(--surface)] p-0.5 text-xs font-semibold">
            {([
              ["list", "List", LayoutList],
              ["calendar", "Kalender", CalendarRange],
            ] as const).map(([key, label, Icon]) => (
              <button
                key={key}
                onClick={() => setScheduleView(key)}
                className={cn(
                  "inline-flex items-center gap-1.5 rounded-md px-3 py-1 transition-colors",
                  scheduleView === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
                )}
              >
                <Icon className="h-3.5 w-3.5" />
                {label}
              </button>
            ))}
          </div>

          {scheduleView === "calendar" ? (
            <MatchCalendar matches={matches} />
          ) : (
            rounds.map(([round, list]) => (
              <div key={round}>
                <h3 className="mb-3 mt-6 text-sm font-bold text-muted-foreground first:mt-0">
                  {isKnockout ? knockoutRoundLabel(list.length) : `Putaran ${round}`}
                </h3>
                <div className="grid gap-2">
                  {list.map((m) => (
                    <MatchCard key={m.id} match={m} orgId={orgId!} eventId={eventId} setBased={setBased} />
                  ))}
                </div>
              </div>
            ))
          )}
        </div>
      ) : activeTab === "stats" ? (
        <div className="max-w-2xl">
          {leaderboardQuery.data && <LeaderboardTable leaderboard={leaderboardQuery.data} />}
        </div>
      ) : (
        <div className="max-w-3xl">
          <StandingsTable standings={standingsQuery.data ?? []} highlight={2} />
          {(standingsQuery.data?.length ?? 0) > 0 && (
            <p className="mt-3 text-xs text-muted-foreground">
              M: main · M/S/K: menang/seri/kalah · GM/GK: gol masuk/kemasukan · SG: selisih gol.
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function MatchCard({
  match,
  orgId,
  eventId,
  setBased,
}: {
  match: Match;
  orgId: string;
  eventId: string;
  setBased: boolean;
}) {
  const qc = useQueryClient();
  const [home, setHome] = useState(match.home_score?.toString() ?? "");
  const [away, setAway] = useState(match.away_score?.toString() ?? "");
  const [showGoals, setShowGoals] = useState(false);

  const save = useMutation({
    mutationFn: () =>
      updateMatchResult(orgId, match.id, {
        home_score: home === "" ? null : Number(home),
        away_score: away === "" ? null : Number(away),
        status: "finished",
      }),
    onSuccess: () => {
      toast.success("Hasil disimpan");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan hasil.").message),
  });

  // Walkover (bye): one team present, no opponent.
  if (match.home_team && !match.away_team) {
    return (
      <Card className="flex items-center gap-3 p-3 text-sm">
        <span className="flex-1 font-semibold">{match.home_team.name}</span>
        <span className="text-xs text-muted-foreground">menang otomatis (bye)</span>
      </Card>
    );
  }

  // A slot still awaiting an earlier result.
  if (!match.home_team || !match.away_team) {
    return (
      <Card className="flex items-center gap-3 p-3 text-sm text-muted-foreground">
        <span className="flex-1 text-right">{match.home_team?.name ?? "TBD"}</span>
        <span className="text-xs">menunggu hasil sebelumnya</span>
        <span className="flex-1">{match.away_team?.name ?? "TBD"}</span>
      </Card>
    );
  }

  // Set-based sports (volleyball/badminton/padel): score per set.
  if (setBased) {
    return (
      <Card className="p-3">
        <SetScoreEditor orgId={orgId} eventId={eventId} match={match} />
        <div className="mt-2 border-t border-border pt-2">
          <Button size="sm" variant="ghost" onClick={() => setShowGoals((v) => !v)}>
            <Goal className="h-4 w-4" />
            Statistik pemain
          </Button>
          {showGoals && <MatchStatsEditor orgId={orgId} eventId={eventId} matchId={match.id} />}
        </div>
      </Card>
    );
  }

  const dirty = home !== (match.home_score?.toString() ?? "") || away !== (match.away_score?.toString() ?? "");
  const canSave = home !== "" && away !== "" && dirty;

  return (
    <Card className="p-3">
      <div className="flex items-center gap-3">
        <span className="flex-1 truncate text-right text-sm font-semibold">{match.home_team.name}</span>
        <Input
          type="number"
          min={0}
          value={home}
          onChange={(e) => setHome(e.target.value)}
          className="h-9 w-14 text-center"
          aria-label={`Skor ${match.home_team.name}`}
        />
        <span className="text-xs text-muted-foreground">vs</span>
        <Input
          type="number"
          min={0}
          value={away}
          onChange={(e) => setAway(e.target.value)}
          className="h-9 w-14 text-center"
          aria-label={`Skor ${match.away_team.name}`}
        />
        <span className="flex-1 truncate text-sm font-semibold">{match.away_team.name}</span>
        <Button
          size="sm"
          variant="outline"
          onClick={() => setShowGoals((v) => !v)}
          aria-label="Statistik pemain"
          title="Statistik pemain"
        >
          <Goal className="h-4 w-4" />
        </Button>
        <Button size="sm" variant={canSave ? "default" : "outline"} disabled={!canSave || save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? "…" : match.status === "finished" && !dirty ? "Tersimpan" : "Simpan"}
        </Button>
      </div>
      {showGoals && <MatchStatsEditor orgId={orgId} eventId={eventId} matchId={match.id} />}
    </Card>
  );
}
