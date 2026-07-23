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
  Plus,
  Trash2,
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
  deleteKnockout,
  updateMatchResult,
  updateMatchTeams,
  createMatch,
  deleteMatch,
  type DrawPayload,
  type ScheduleOptions,
  type CreateMatchPayload,
  type MatchTeamsPayload,
  type SeedingPayload,
} from "@/lib/api/matches";
import { getEvent, getRegistrations } from "@/lib/api/events";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { useConfirm } from "@/components/shared/confirm-provider";
import {
  buildMatchSections,
  isKnockout as isKnockoutFormat,
  isDoubleElim,
  isHybrid as isHybridFormat,
  isThirdPlace,
  phaseLabel,
} from "@/lib/bracket";
import { groupNames, hybridConfig, knockoutMatches } from "@/lib/hybrid";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { dateKeyOf, defaultDateKey, fullDateLabel, groupByDate } from "@/lib/match-dates";
import { EventTimezoneProvider } from "@/components/event/event-timezone";
import { isSetBased, scoreColumnLegend } from "@/lib/scoring";
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
import { PillTabs } from "@/components/event/pill-tabs";
import { BracketView } from "@/components/event/bracket-view";
import { DoubleBracketView } from "@/components/event/double-bracket-view";
import { LeaderboardTable } from "@/components/event/leaderboard-table";
import { MatchStatsEditor } from "@/components/event/match-stats-editor";
import { MatchScheduleEditor } from "@/components/event/match-schedule-editor";
import { SetScoreEditor } from "@/components/event/set-score-editor";
import { RubberScoreEditor } from "@/components/event/rubber-score-editor";
import { MatchCalendar } from "@/components/event/match-calendar";
import { MatchCardHeader } from "@/components/event/match-card-header";
import { ScheduleSettingsDialog } from "@/components/event/schedule-settings-dialog";
import { ManualMatchDialog } from "@/components/event/manual-match-dialog";
import { SlotTeamsDialog } from "@/components/event/slot-teams-dialog";
import { KnockoutSeedDialog } from "@/components/event/knockout-seed-dialog";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

type Tab = "schedule" | "standings" | "bracket" | "stats";

export default function SchedulePage() {
  const { orgId } = useActiveOrg();
  const confirm = useConfirm();
  const { id: eventId } = useParams<{ id: string }>();
  const [tab, setTab] = useState<Tab | null>(null);
  const [categoryId, setCategoryId] = useState<string>("");
  const [scheduleView, setScheduleView] = useState<"list" | "calendar">("list");
  const [scheduleDialog, setScheduleDialog] = useState(false);
  const [drawDialog, setDrawDialog] = useState(false);
  const [manualDialog, setManualDialog] = useState(false);
  const [seedDialog, setSeedDialog] = useState(false);
  // The bracket slot being re-seated, plus any inline errors it came back with.
  const [slotMatch, setSlotMatch] = useState<Match | null>(null);
  const [slotErrors, setSlotErrors] = useState<FieldErrors>({});
  const [dateKey, setDateKey] = useState<string | null>(null);

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  // Kickoff times belong to the venue's zone, not the organizer's browser.
  const tz = eventQuery.data?.timezone ?? "Asia/Jakarta";

  // Each category runs its own format, so the schedule/standings/bracket below
  // are all scoped to the one the organizer has selected.
  const categories = eventQuery.data?.categories ?? [];
  const selectedCategory = categories.find((c) => c.id === categoryId) ?? categories[0] ?? null;
  const catId = selectedCategory?.id;

  const matchesQuery = useQuery({
    queryKey: ["matches", orgId, eventId, catId],
    queryFn: () => getMatches(orgId!, eventId, catId!),
    enabled: !!orgId && !!catId,
  });
  const standingsQuery = useQuery({
    queryKey: ["standings", orgId, eventId, catId],
    queryFn: () => getStandings(orgId!, eventId, catId!),
    enabled: !!orgId && !!catId,
  });
  const leaderboardQuery = useQuery({
    queryKey: ["leaderboard", orgId, eventId, catId],
    queryFn: () => getLeaderboard(orgId!, eventId, catId!),
    enabled: !!orgId && !!catId,
  });

  const qc = useQueryClient();
  const catalog = useCatalog();
  const matches = matchesQuery.data ?? [];
  // Branch on the engine, not the format key: a preset can be named anything.
  const engine = selectedCategory?.engine ?? null;
  const isKnockout = isKnockoutFormat(engine);
  const isDouble = isDoubleElim(engine);
  const isHybrid = isHybridFormat(engine);
  const setBased = isSetBased(eventQuery.data);
  const config = hybridConfig(
    selectedCategory?.bracket_config,
    catalog.tiebreakers.map((t) => t.key),
  );
  const activeTab: Tab = tab ?? (isKnockout ? "bracket" : "schedule");

  // Registrations are event-wide; the group draw and the manual-match dialog
  // both narrow to this category's approved teams below.
  const teamsQuery = useQuery({
    queryKey: ["registrations", orgId, eventId],
    queryFn: () => getRegistrations(orgId!, eventId),
    enabled: !!orgId,
  });
  // The planned bracket, shown until the real one is built.
  const planQuery = useQuery({
    queryKey: ["knockout-plan", orgId, eventId, catId],
    queryFn: () => getKnockoutPlan(orgId!, eventId, catId!),
    enabled: !!orgId && isHybrid && !!catId,
  });
  const approvedTeams = (teamsQuery.data ?? []).filter(
    (t) => t.status === "approved" && t.category_id === catId,
  );

  const refreshEventData = () => {
    qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["registrations", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["knockout-plan", orgId, eventId] });
  };

  const generate = useMutation({
    mutationFn: (options: ScheduleOptions & SeedingPayload) =>
      generateSchedule(orgId!, eventId, catId!, options),
    onSuccess: (created) => {
      toast.success(`Jadwal dibuat: ${created.length} pertandingan`);
      setScheduleDialog(false);
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat jadwal.").message),
  });

  const draw = useMutation({
    mutationFn: (payload: DrawPayload) => drawGroups(orgId!, eventId, catId!, payload),
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
    mutationFn: (seeding?: SeedingPayload) => generateKnockout(orgId!, eventId, catId!, seeding),
    onSuccess: () => {
      toast.success("Bracket knockout dibuat dari tim yang lolos fase grup");
      setTab("bracket");
      setSeedDialog(false);
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat bracket.").message),
  });

  const editSlot = useMutation({
    mutationFn: (payload: MatchTeamsPayload) => updateMatchTeams(orgId!, slotMatch!.id, payload),
    onSuccess: () => {
      toast.success("Tim di slot bracket diperbarui");
      setSlotMatch(null);
      setSlotErrors({});
      refreshEventData();
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err, "Gagal mengganti tim.");
      setSlotErrors(fieldErrors);
      // Field-level problems are already spelled out beside the select.
      if (Object.keys(fieldErrors).length === 0) toast.error(message);
    },
  });

  const dropKnockout = useMutation({
    mutationFn: () => deleteKnockout(orgId!, eventId, catId!),
    onSuccess: () => {
      toast.success("Bracket dihapus", {
        description: "Fase grup dan hasilnya tidak ikut terhapus.",
      });
      // The bracket tab now falls back to the plan, which may be stale.
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus bracket.").message),
  });

  const addManual = useMutation({
    mutationFn: (payload: CreateMatchPayload) => createMatch(orgId!, eventId, catId!, payload),
    onSuccess: () => {
      toast.success("Pertandingan ditambahkan");
      setManualDialog(false);
      refreshEventData();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menambah pertandingan.").message),
  });

  const sections = buildMatchSections(matches, isKnockout, isDouble, isHybrid);

  // Only when it says something the section heading doesn't: a matchday mixes
  // the groups, and a knockout round number isn't a name. A league fixture's
  // "Pekan 3" would just repeat its own heading, so it gets no chip.
  const phaseOf = (m: Match) =>
    m.group_name || m.stage === "knockout" || isKnockout || isThirdPlace(m)
      ? phaseLabel(m, matches, isKnockout)
      : undefined;
  const knockoutTies = knockoutMatches(matches);

  // Who a bracket slot may be handed to. A hybrid bracket only accepts the
  // group qualifiers — which is exactly who already holds its slots — while
  // single elimination accepts any approved team, late entries included.
  const slotPool = isHybrid
    ? [
        ...new Map(
          knockoutTies
            .flatMap((m) => [m.home_team, m.away_team])
            .filter((t): t is NonNullable<typeof t> => !!t)
            .map((t) => [t.id, { id: t.id, name: t.name }]),
        ).values(),
      ]
    : approvedTeams;

  // The list view shows one matchday at a time; round/group headings are kept
  // inside the day, so a fixture never loses its context.
  const dateGroups = groupByDate(matches, tz);
  const activeDateKey =
    dateKey && dateGroups.some((g) => g.key === dateKey) ? dateKey : defaultDateKey(dateGroups, tz);
  const activeDateGroup = dateGroups.find((g) => g.key === activeDateKey);
  const daySections = sections
    .map(([label, list]) => [
      label,
      list
        .filter((m) => dateKeyOf(m.scheduled_at, tz) === activeDateKey)
        // Earliest kickoff first — the API orders by round/insertion, so without
        // this the top card is just whichever fixture was created first, not the
        // one that starts first. Unscheduled fixtures sink to the bottom; equal
        // times keep their section order (Array.sort is stable).
        .sort((a, b) => {
          const ta = a.scheduled_at ? new Date(a.scheduled_at).getTime() : Infinity;
          const tb = b.scheduled_at ? new Date(b.scheduled_at).getTime() : Infinity;
          return ta - tb;
        }),
    ] as [string, Match[]])
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
    <EventTimezoneProvider timezone={tz}>
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
            <Button
              variant="outline"
              onClick={() => setManualDialog(true)}
              disabled={addManual.isPending || !orgId}
            >
              <Plus className="h-4 w-4" />
              Tambah Manual
            </Button>
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

      {categories.length > 1 && (
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Kategori
          </span>
          <PillTabs
            tone="tint"
            items={categories.map((c) => ({ key: c.id, label: c.name }))}
            activeKey={selectedCategory?.id ?? ""}
            onSelect={(key) => {
              setCategoryId(key);
              // Tabs and matchday differ per category — recompute defaults.
              setTab(null);
              setDateKey(null);
            }}
          />
        </div>
      )}

      <div className="mb-6">
        <PillTabs
          items={tabs.map(([key, label, icon]) => ({ key, label, icon }))}
          activeKey={activeTab}
          onSelect={(key) => setTab(key as Tab)}
        />
      </div>

      {matchesQuery.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-16 w-full rounded-xl" />
          ))}
        </div>
      ) : /* The table stands on the draw alone, so it must outlive this gate: a
            category with groups but no fixtures still has something to show. */
      matches.length === 0 && activeTab !== "standings" ? (
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
            <div className="flex flex-wrap items-center justify-center gap-2">
              <Button onClick={() => setScheduleDialog(true)} disabled={generate.isPending}>
                <Sparkles className="h-4 w-4" />
                Buat Jadwal
              </Button>
              <Button
                variant="outline"
                onClick={() => setManualDialog(true)}
                disabled={addManual.isPending}
              >
                <Plus className="h-4 w-4" />
                Tambah Manual
              </Button>
            </div>
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
              <Button onClick={() => setSeedDialog(true)} disabled={knockout.isPending}>
                <Sparkles className="h-4 w-4" />
                {knockout.isPending ? "Membuat…" : "Buat Bracket Knockout"}
              </Button>
            </div>
          </div>
        ) : (
          <div className="grid gap-3">
            <Card className="overflow-x-auto p-4 md:p-6">
              {isDouble ? (
                // Double elimination propagates losers too, so a slot edit
                // would have two topologies to unwind — not offered yet.
                <DoubleBracketView matches={matches} />
              ) : (
                <BracketView
                  matches={isHybrid ? knockoutTies : matches}
                  onEditSlot={setSlotMatch}
                />
              )}
            </Card>
            {isHybrid && (
              <div className="flex flex-wrap justify-end gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    // Spell out what's at stake: a bracket with results entered
                    // is not the same undo as one generated a minute ago. No
                    // strip at all when nothing has been played — an empty
                    // warning teaches people to skip the real ones.
                    const played = knockoutTies.filter((m) => m.status === "finished").length;
                    void confirm({
                      title: "Hapus bracket knockout?",
                      description: "Fase grup dan hasilnya tetap aman.",
                      consequences:
                        played > 0 ? `${played} hasil pertandingan akan ikut hilang.` : undefined,
                      confirmLabel: "Hapus bracket",
                      tone: "danger",
                      icon: Trash2,
                    }).then((ok) => ok && dropKnockout.mutate());
                  }}
                  disabled={dropKnockout.isPending || knockout.isPending}
                  className="text-muted-foreground hover:text-[var(--danger)]"
                >
                  <Trash2 className="h-4 w-4" />
                  {dropKnockout.isPending ? "Menghapus…" : "Hapus Bracket"}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setSeedDialog(true)}
                  disabled={knockout.isPending || dropKnockout.isPending}
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
                  {fullDateLabel(activeDateGroup?.iso ?? null, tz)}
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
                    {/* Wide screens fit two fixtures side by side. grid-cols-1
                        is load-bearing on phones: an implicit `auto` track is
                        sized by its widest child's min-content, so one card that
                        refuses to shrink drags the whole page wider than the
                        viewport. minmax(0,1fr) — what grid-cols-* compiles to —
                        caps that. */}
                    <div className="grid grid-cols-1 items-start gap-3 xl:grid-cols-2">
                      {list.map((m) => (
                        <MatchCard
                          key={m.id}
                          match={m}
                          orgId={orgId!}
                          eventId={eventId}
                          setBased={setBased}
                          rubbers={!!selectedCategory?.uses_rubbers}
                          knockout={isKnockout || m.stage === "knockout"}
                          phase={phaseOf(m)}
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
            <GroupStandings
              standings={standingsQuery.data ?? []}
              config={config}
              category={selectedCategory}
            />
          ) : (
            // A standalone league ends at the table — nothing follows it to
            // qualify for — so only the leader is marked. Two green rows here
            // would promise a knockout stage this format cannot produce.
            <StandingsTable
              standings={standingsQuery.data ?? []}
              highlight={1}
              category={selectedCategory}
            />
          )}
          {(standingsQuery.data?.length ?? 0) > 0 && (
            <p className="mt-3 text-xs text-muted-foreground">
              {/* GroupStandings prints its own "lolos ke knockout" legend, so the
                  green one here belongs to the standalone league only. */}
              {!isHybrid && "Baris hijau = juara klasemen. "}
              {scoreColumnLegend(selectedCategory)}
            </p>
          )}
        </div>
      )}

      <ManualMatchDialog
        // A fresh mount per open, so the form always starts empty.
        key={String(manualDialog)}
        open={manualDialog}
        teams={approvedTeams}
        orgId={orgId!}
        eventId={eventId}
        categoryId={catId!}
        groups={isHybrid ? groupNames(config) : []}
        pending={addManual.isPending}
        onClose={() => setManualDialog(false)}
        onSubmit={(payload) => addManual.mutate(payload)}
      />

      {eventQuery.data && (
        <ScheduleSettingsDialog
          event={eventQuery.data}
          open={scheduleDialog}
          hasMatches={matches.length > 0}
          pending={generate.isPending}
          seedTeams={engine === "knockout_single" ? approvedTeams : undefined}
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

      {isHybrid && (
        <KnockoutSeedDialog
          open={seedDialog}
          plan={planQuery.data}
          hasBracket={knockoutTies.length > 0}
          pending={knockout.isPending}
          tz={tz}
          onClose={() => setSeedDialog(false)}
          onSubmit={(payload) => knockout.mutate(payload)}
        />
      )}

      {slotMatch && (
        <SlotTeamsDialog
          open
          match={slotMatch}
          bracket={isHybrid ? knockoutTies : matches}
          teams={slotPool}
          pending={editSlot.isPending}
          fieldErrors={slotErrors}
          onClose={() => {
            setSlotMatch(null);
            setSlotErrors({});
          }}
          onSubmit={(payload) => editSlot.mutate(payload)}
        />
      )}
    </div>
    </EventTimezoneProvider>
  );
}

function MatchCard({
  match,
  orgId,
  eventId,
  setBased,
  rubbers,
  knockout,
  phase,
}: {
  match: Match;
  orgId: string;
  eventId: string;
  setBased: boolean;
  /** A squad tie: scored per partai, never as one scoreline. */
  rubbers: boolean;
  /** A tie that must produce a winner — level scores go to penalties. */
  knockout: boolean;
  /** "Grup A", "Semifinal" — omitted when it would only repeat the heading. */
  phase?: string;
}) {
  const qc = useQueryClient();
  const confirm = useConfirm();
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

  const del = useMutation({
    mutationFn: () => deleteMatch(orgId, match.id),
    onSuccess: () => {
      toast.success("Pertandingan dihapus");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus pertandingan.").message),
  });

  const removeBtn = (
    <Button
      size="sm"
      variant="ghost"
      onClick={() =>
        void confirm({
          title: "Hapus pertandingan ini?",
          description: "Jadwal dan skor yang sudah diisi ikut terhapus.",
          confirmLabel: "Hapus",
          tone: "danger",
          icon: Trash2,
        }).then((ok) => ok && del.mutate())
      }
      disabled={del.isPending}
      aria-label="Hapus pertandingan"
      title="Hapus pertandingan"
      className="text-muted-foreground hover:text-[var(--danger)]"
    >
      <Trash2 className="h-4 w-4" />
    </Button>
  );

  // Cancelled: read-only. The score row below hardcodes `status: "finished"` on
  // save, so leaving it live would let one click un-cancel the fixture through
  // the other endpoint — the two doors would disagree in the UI.
  if (match.status === "cancelled") {
    return (
      <Card className="p-3 opacity-60">
        <MatchCardHeader orgId={orgId} eventId={eventId} match={match} knockout={knockout} phase={phase} />
        <div className="mt-3 flex items-center gap-3 text-sm text-muted-foreground">
          <span className="flex-1 truncate text-right font-medium">{match.home_team?.name ?? "TBD"}</span>
          <span className="text-xs">
            {match.home_score !== null && match.away_score !== null
              ? `${match.home_score}–${match.away_score}`
              : "vs"}
          </span>
          <span className="flex-1 truncate font-medium">{match.away_team?.name ?? "TBD"}</span>
          {removeBtn}
        </div>
      </Card>
    );
  }

  // Walkover (bye): one team present, no opponent, already settled. The
  // "Menang WO" badge in the header says so — no second label here.
  if (match.home_team && !match.away_team && match.status === "finished") {
    return (
      <Card className="p-3 text-sm">
        <MatchCardHeader orgId={orgId} eventId={eventId} match={match} knockout={knockout} phase={phase} />
        <div className="mt-3 flex items-center gap-3">
          <span className="flex-1 font-semibold">{match.home_team.name}</span>
          {removeBtn}
        </div>
      </Card>
    );
  }

  // A slot still awaiting an earlier result — teams TBD, but it can still be scheduled.
  if (!match.home_team || !match.away_team) {
    return (
      <Card className="p-3">
        <MatchCardHeader orgId={orgId} eventId={eventId} match={match} knockout={knockout} phase={phase} />
        <div className="mt-3 flex items-center gap-3 text-sm text-muted-foreground">
          <span className="flex-1 truncate text-right font-medium">{match.home_team?.name ?? "TBD"}</span>
          <span className="text-xs">menunggu hasil sebelumnya</span>
          <span className="flex-1 truncate font-medium">{match.away_team?.name ?? "TBD"}</span>
        </div>
        <div className="mt-3 flex items-start justify-between gap-2 border-t border-border pt-3">
          <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
          {removeBtn}
        </div>
      </Card>
    );
  }

  // A squad tie (badminton beregu & co): several partai, each scored per set,
  // and the tie's own scoreline is what they add up to. Checked before the
  // plain set-based branch, which such a category would otherwise fall into.
  if (rubbers) {
    return (
      <Card className={cn("p-3", showGoals && "xl:col-span-2")}>
        <MatchCardHeader orgId={orgId} eventId={eventId} match={match} knockout={knockout} phase={phase} />
        <div className="mt-3">
          <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
        </div>
        <div className="mt-3 border-t border-border pt-3">
          <RubberScoreEditor orgId={orgId} eventId={eventId} match={match} />
        </div>
        <div className="mt-2 flex items-center justify-end border-t border-border pt-2">
          {removeBtn}
        </div>
      </Card>
    );
  }

  // Set-based sports (volleyball/badminton/padel): score per set.
  if (setBased) {
    return (
      <Card className={cn("p-3", showGoals && "xl:col-span-2")}>
        <MatchCardHeader orgId={orgId} eventId={eventId} match={match} knockout={knockout} phase={phase} />
        <div className="mt-3">
          <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
        </div>
        <div className="mt-3 border-t border-border pt-3">
          <SetScoreEditor orgId={orgId} eventId={eventId} match={match} />
        </div>
        <div className="mt-2 border-t border-border pt-2">
          <div className="flex flex-wrap items-center justify-end gap-2">
            <div className="flex items-center gap-1">
              <Button size="sm" variant="ghost" onClick={() => setShowGoals((v) => !v)}>
                <Goal className="h-4 w-4" />
                Statistik pemain
              </Button>
              {removeBtn}
            </div>
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
      <MatchCardHeader orgId={orgId} eventId={eventId} match={match} knockout={knockout} phase={phase} />
      <div className="mt-3">
        <MatchScheduleEditor orgId={orgId} eventId={eventId} match={match} />
      </div>
      <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-border pt-3">
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
        {/* The actions travel as one block. Left loose in the wrapping row they
            break up one at a time, so a phone gets "Simpan" stranded on a line
            by itself while the scoreline keeps the other two. */}
        <div className="ml-auto flex items-center gap-3">
          {removeBtn}
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
      {showGoals && <MatchStatsEditor orgId={orgId} eventId={eventId} matchId={match.id} match={match} />}
    </Card>
  );
}
