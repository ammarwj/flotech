"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  CalendarClock,
  ListOrdered,
  Network,
  Shuffle,
  Sparkles,
  Goal,
  LayoutList,
  CalendarRange,
} from "lucide-react";
import { toast } from "sonner";

import {
  getMatches,
  getStandings,
  getLeaderboard,
  generateSchedule,
  drawGroups,
  getKnockoutPlan,
  generateKnockout,
  updateMatchResult,
  type DrawPayload,
  type ScheduleOptions,
} from "@/lib/api/matches";
import { getEvent, getRegistrations } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import {
  buildMatchSections,
  isKnockout as isKnockoutFormat,
  isDoubleElim,
  isHybrid as isHybridFormat,
} from "@/lib/bracket";
import { hybridConfig, knockoutMatches } from "@/lib/hybrid";
import { dateKeyOf, defaultDateKey, fullDateLabel, groupByDate } from "@/lib/match-dates";
import { isSetBased } from "@/lib/scoring";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { StandingsTable } from "@/components/event/standings-table";
import { GroupStandings } from "@/components/event/group-standings";
import { GroupDrawDialog } from "@/components/event/group-draw-dialog";
import { KnockoutPlanView } from "@/components/event/knockout-plan-view";
import { MatchDayTabs } from "@/components/event/match-day-tabs";
import { BracketView } from "@/components/event/bracket-view";
import { DoubleBracketView } from "@/components/event/double-bracket-view";
import { LeaderboardTable } from "@/components/event/leaderboard-table";
import { MatchStatsEditor } from "@/components/event/match-stats-editor";
import { MatchScheduleEditor } from "@/components/event/match-schedule-editor";
import { SetScoreEditor } from "@/components/event/set-score-editor";
import { MatchCalendar } from "@/components/event/match-calendar";
import { MatchConfirmBar } from "@/components/event/match-confirm-bar";
import { ScheduleSettingsDialog } from "@/components/event/schedule-settings-dialog";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

type Tab = "schedule" | "standings" | "bracket" | "stats";

export default function SchedulePage() {
  const { orgId } = useActiveOrg();
  const { id: eventId } = useParams<{ id: string }>();
  const [tab, setTab] = useState<Tab | null>(null);
  const [scheduleView, setScheduleView] = useState<"list" | "calendar">("list");
  const [scheduleDialog, setScheduleDialog] = useState(false);
  const [drawDialog, setDrawDialog] = useState(false);
  const [dateKey, setDateKey] = useState<string | null>(null);

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
  const format = eventQuery.data?.tournament_format;
  const isKnockout = format ? isKnockoutFormat(format) : false;
  const isDouble = format ? isDoubleElim(format) : false;
  const isHybrid = format ? isHybridFormat(format) : false;
  const setBased = eventQuery.data ? isSetBased(eventQuery.data.sport_type) : false;
  const config = hybridConfig(eventQuery.data?.bracket_config);
  const activeTab: Tab = tab ?? (isKnockout ? "bracket" : "schedule");

  // Only a hybrid event needs the roster, for the group draw.
  const teamsQuery = useQuery({
    queryKey: ["registrations", orgId, eventId],
    queryFn: () => getRegistrations(orgId!, eventId),
    enabled: !!orgId && isHybrid,
  });
  // The planned bracket, shown until the real one is built.
  const planQuery = useQuery({
    queryKey: ["knockout-plan", orgId, eventId],
    queryFn: () => getKnockoutPlan(orgId!, eventId),
    enabled: !!orgId && isHybrid,
  });
  const approvedTeams = (teamsQuery.data ?? []).filter((t) => t.status === "approved");

  const refreshEventData = () => {
    qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["registrations", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["knockout-plan", orgId, eventId] });
  };

  const generate = useMutation({
    mutationFn: (options: ScheduleOptions) => generateSchedule(orgId!, eventId, options),
    onSuccess: (created) => {
      toast.success(`Jadwal dibuat: ${created.length} pertandingan`);
      setScheduleDialog(false);
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat jadwal.").message),
  });

  const draw = useMutation({
    mutationFn: (payload: DrawPayload) => drawGroups(orgId!, eventId, payload),
    onSuccess: () => {
      toast.success("Undian grup selesai", {
        description: "Buat ulang jadwal agar cocok dengan isi grup yang baru.",
      });
      setDrawDialog(false);
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengundi grup.").message),
  });

  const knockout = useMutation({
    mutationFn: () => generateKnockout(orgId!, eventId),
    onSuccess: () => {
      toast.success("Bracket knockout dibuat dari tim yang lolos fase grup");
      setTab("bracket");
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat bracket.").message),
  });

  const sections = buildMatchSections(matches, isKnockout, isDouble, isHybrid);
  const knockoutTies = knockoutMatches(matches);

  // The list view shows one matchday at a time; round/group headings are kept
  // inside the day, so a fixture never loses its context.
  const dateGroups = groupByDate(matches);
  const activeDateKey =
    dateKey && dateGroups.some((g) => g.key === dateKey) ? dateKey : defaultDateKey(dateGroups);
  const activeDateGroup = dateGroups.find((g) => g.key === activeDateKey);
  const daySections = sections
    .map(([label, list]) => [label, list.filter((m) => dateKeyOf(m.scheduled_at) === activeDateKey)] as [string, Match[]])
    .filter(([, list]) => list.length > 0);

  const tabs: [Tab, string, typeof CalendarClock][] = isKnockout
    ? [
        ["bracket", "Bracket", Network],
        ["schedule", "Jadwal & Hasil", CalendarClock],
        ["stats", "Statistik", Goal],
      ]
    : isHybrid
      ? [
          ["schedule", "Jadwal", CalendarClock],
          ["standings", "Klasemen Grup", ListOrdered],
          ["bracket", "Bracket", Network],
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
          <div className="flex flex-wrap items-center gap-2">
            {isHybrid && (
              <Button
                variant="outline"
                onClick={() => setDrawDialog(true)}
                disabled={draw.isPending || !orgId}
              >
                <Shuffle className="h-4 w-4" />
                Undian Grup
              </Button>
            )}
            <Button onClick={() => setScheduleDialog(true)} disabled={generate.isPending || !orgId}>
              <Sparkles className="h-4 w-4" />
              {generate.isPending
                ? "Membuat…"
                : matches.length > 0
                  ? isHybrid
                    ? "Buat Ulang Jadwal Grup"
                    : "Buat Ulang Jadwal"
                  : "Buat Jadwal"}
            </Button>
          </div>
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
              : isHybrid
                ? `Tim diundi ke ${config.groups} grup, lalu jadwal fase grup dibuat otomatis. Bracket knockout menyusul setelah grup selesai.`
                : "Buat jadwal round-robin otomatis dari tim yang sudah disetujui. Butuh minimal 2 tim."
          }
          action={
            <Button onClick={() => setScheduleDialog(true)} disabled={generate.isPending}>
              <Sparkles className="h-4 w-4" />
              Buat Jadwal
            </Button>
          }
        />
      ) : activeTab === "bracket" ? (
        isHybrid && knockoutTies.length === 0 ? (
          // No bracket yet — show the plan, so the pairings are known while the
          // groups are still being played.
          <div className="grid gap-4">
            {planQuery.data ? (
              <KnockoutPlanView plan={planQuery.data} />
            ) : (
              <Skeleton className="h-40 w-full rounded-xl" />
            )}
            <div className="flex justify-end">
              <Button onClick={() => knockout.mutate()} disabled={knockout.isPending}>
                <Sparkles className="h-4 w-4" />
                {knockout.isPending ? "Membuat…" : "Buat Bracket Knockout"}
              </Button>
            </div>
          </div>
        ) : (
          <div className="grid gap-3">
            <Card className="overflow-x-auto p-4 md:p-6">
              {isDouble ? (
                <DoubleBracketView matches={matches} />
              ) : (
                <BracketView matches={isHybrid ? knockoutTies : matches} />
              )}
            </Card>
            {isHybrid && (
              <div className="flex justify-end">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => knockout.mutate()}
                  disabled={knockout.isPending}
                >
                  <Sparkles className="h-4 w-4" />
                  {knockout.isPending ? "Membuat…" : "Buat Ulang Bracket"}
                </Button>
              </div>
            )}
          </div>
        )
      ) : activeTab === "schedule" ? (
        <div>
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
            <>
              {/* One matchday at a time, like the public page. */}
              <MatchDayTabs
                groups={dateGroups}
                activeKey={activeDateKey}
                onSelect={setDateKey}
              />

              <div className="mb-6 grid gap-1 border-b border-border pb-3">
                <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
                  {fullDateLabel(activeDateGroup?.iso ?? null)}
                </h2>
                <p className="text-xs text-muted-foreground">
                  {activeDateGroup?.list.length ?? 0} pertandingan
                </p>
              </div>

              {/* Sections (matchday / knockout round) are spaced apart from each
                  other, not just from the day heading. */}
              <div className="grid gap-8">
                {daySections.map(([label, list]) => (
                  // Spacing via gap, not heading margins: a global `h1..h4 {
                  // margin: 0 }` can out-rank margin utilities.
                  <div key={label} className="grid gap-4">
                    <h3 className="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                      {label}
                    </h3>
                    {/* Wide screens fit two fixtures side by side. */}
                    <div className="grid items-start gap-3 xl:grid-cols-2">
                      {list.map((m) => (
                        <MatchCard
                          key={m.id}
                          match={m}
                          orgId={orgId!}
                          eventId={eventId}
                          setBased={setBased}
                          knockout={isKnockout || m.stage === "knockout"}
                        />
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      ) : activeTab === "stats" ? (
        <div>
          {leaderboardQuery.data && (
            <LeaderboardTable
              leaderboard={leaderboardQuery.data}
              eventName={eventQuery.data?.name}
            />
          )}
        </div>
      ) : (
        <div className={isHybrid ? undefined : "max-w-3xl"}>
          {isHybrid ? (
            <GroupStandings standings={standingsQuery.data ?? []} config={config} />
          ) : (
            <StandingsTable standings={standingsQuery.data ?? []} highlight={2} />
          )}
          {(standingsQuery.data?.length ?? 0) > 0 && (
            <p className="mt-3 text-xs text-muted-foreground">
              M: main · M/S/K: menang/seri/kalah · GM/GK: gol masuk/kemasukan · SG: selisih gol.
            </p>
          )}
        </div>
      )}

      {eventQuery.data && (
        <ScheduleSettingsDialog
          event={eventQuery.data}
          open={scheduleDialog}
          hasMatches={matches.length > 0}
          pending={generate.isPending}
          onClose={() => setScheduleDialog(false)}
          onSubmit={(options) => generate.mutate(options)}
        />
      )}

      {isHybrid && (
        <GroupDrawDialog
          open={drawDialog}
          teams={approvedTeams}
          config={config}
          hasMatches={matches.length > 0}
          pending={draw.isPending}
          onClose={() => setDrawDialog(false)}
          onSubmit={(payload) => draw.mutate(payload)}
        />
      )}
    </div>
  );
}

function MatchCard({
  match,
  orgId,
  eventId,
  setBased,
  knockout,
}: {
  match: Match;
  orgId: string;
  eventId: string;
  setBased: boolean;
  /** A tie that must produce a winner — level scores go to penalties. */
  knockout: boolean;
}) {
  const qc = useQueryClient();
  const [home, setHome] = useState(match.home_score?.toString() ?? "");
  const [away, setAway] = useState(match.away_score?.toString() ?? "");
  const [homePen, setHomePen] = useState(match.home_penalty?.toString() ?? "");
  const [awayPen, setAwayPen] = useState(match.away_penalty?.toString() ?? "");
  const [showGoals, setShowGoals] = useState(false);

  // A level knockout tie is settled on penalties, so the shootout fields appear
  // exactly when they're needed.
  const level = home !== "" && home === away;
  const needsPenalties = knockout && level;

  const save = useMutation({
    mutationFn: () =>
      updateMatchResult(orgId, match.id, {
        home_score: home === "" ? null : Number(home),
        away_score: away === "" ? null : Number(away),
        home_penalty: needsPenalties && homePen !== "" ? Number(homePen) : null,
        away_penalty: needsPenalties && awayPen !== "" ? Number(awayPen) : null,
        status: "finished",
      }),
    onSuccess: () => {
      toast.success("Hasil disimpan");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan hasil.").message),
  });

  // Walkover (bye): one team present, no opponent, already settled.
  if (match.home_team && !match.away_team && match.status === "finished") {
    return (
      <Card className="flex items-center gap-3 p-3 text-sm">
        <span className="flex-1 font-semibold">{match.home_team.name}</span>
        <span className="text-xs text-muted-foreground">menang otomatis (bye)</span>
      </Card>
    );
  }

  // A slot still awaiting an earlier result — teams TBD, but it can still be scheduled.
  if (!match.home_team || !match.away_team) {
    return (
      <Card className="p-3">
        <div className="flex items-center gap-3 text-sm text-muted-foreground">
          <span className="flex-1 truncate text-right font-medium">{match.home_team?.name ?? "TBD"}</span>
          <span className="text-xs">menunggu hasil sebelumnya</span>
          <span className="flex-1 truncate font-medium">{match.away_team?.name ?? "TBD"}</span>
        </div>
        <div className="mt-3 border-t border-border pt-3">
          <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
        </div>
      </Card>
    );
  }

  // Set-based sports (volleyball/badminton/padel): score per set.
  if (setBased) {
    return (
      <Card className={cn("p-3", showGoals && "xl:col-span-2")}>
        <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
        <div className="mt-3 border-t border-border pt-3">
          <SetScoreEditor orgId={orgId} eventId={eventId} match={match} />
        </div>
        <div className="mt-2 border-t border-border pt-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <MatchConfirmBar orgId={orgId} eventId={eventId} match={match} />
            <Button size="sm" variant="ghost" onClick={() => setShowGoals((v) => !v)}>
              <Goal className="h-4 w-4" />
              Statistik pemain
            </Button>
          </div>
          {showGoals && <MatchStatsEditor orgId={orgId} eventId={eventId} matchId={match.id} match={match} />}
        </div>
      </Card>
    );
  }

  const dirty =
    home !== (match.home_score?.toString() ?? "") ||
    away !== (match.away_score?.toString() ?? "") ||
    homePen !== (match.home_penalty?.toString() ?? "") ||
    awayPen !== (match.away_penalty?.toString() ?? "");
  const penaltiesOk = !needsPenalties || (homePen !== "" && awayPen !== "" && homePen !== awayPen);
  const canSave = home !== "" && away !== "" && dirty && penaltiesOk;

  return (
    // Opening the stat editor needs the full row; a half-width card squashes it.
    <Card className={cn("p-3", showGoals && "xl:col-span-2")}>
      <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
      <div className="mt-3 flex items-center gap-3 border-t border-border pt-3">
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
              aria-label={`Penalti ${match.home_team.name}`}
            />
            <span className="text-xs text-muted-foreground">–</span>
            <Input
              type="number"
              min={0}
              value={awayPen}
              onChange={(e) => setAwayPen(e.target.value)}
              className="h-8 w-12 text-center"
              aria-label={`Penalti ${match.away_team.name}`}
            />
          </div>
          <p className="text-xs text-muted-foreground">
            {penaltiesOk
              ? "Pemenang adu penalti yang lolos ke babak berikutnya."
              : "Skor imbang — isi hasil penalti, tidak boleh sama."}
          </p>
        </div>
      )}

      {match.status === "finished" && (
        <div className="mt-2 border-t border-border pt-2">
          <MatchConfirmBar orgId={orgId} eventId={eventId} match={match} />
        </div>
      )}
      {showGoals && <MatchStatsEditor orgId={orgId} eventId={eventId} matchId={match.id} match={match} />}
    </Card>
  );
}
