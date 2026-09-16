"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { CalendarClock, ClipboardCheck, Goal, Printer } from "lucide-react";
import { toast } from "sonner";

import {
  downloadOfficiatingLineupSheet,
  getOfficiatingDiscipline,
  getOfficiatingEvent,
  getOfficiatingMatches,
} from "@/lib/api/officiating";
import { parseApiError } from "@/lib/api/errors";
import { officiatingResultGateway, officiatingStatsGateway } from "@/lib/match-doors";
import { isSetBased, tracksDiscipline } from "@/lib/scoring";
import {
  buildMatchSections,
  crestGradient,
  isDecider,
  isDoubleElim,
  isHybrid as isHybridFormat,
  isKnockout as isKnockoutFormat,
  isThirdPlace,
  matchWinnerId,
  phaseLabel,
  wentToPenalties,
} from "@/lib/bracket";
import {
  dateKeyOf,
  defaultDateKey,
  fullDateLabel,
  groupByDate,
  timeOf,
  tzLabel,
} from "@/lib/match-dates";
import { useUrlState } from "@/lib/hooks/use-url-state";
import { cn } from "@/lib/utils";
import { EventTimezoneProvider, useEventTimezone } from "@/components/event/event-timezone";
import { MatchDayTabs } from "@/components/event/match-day-tabs";
import { MatchDisciplineNotice } from "@/components/event/match-discipline-notice";
import { MatchStatsEditor } from "@/components/event/match-stats-editor";
import { GoalScoreEditor } from "@/components/event/goal-score-editor";
import { SetScoreEditor } from "@/components/event/set-score-editor";
import { PillTabs } from "@/components/event/pill-tabs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { MatchStatusBadge } from "@/components/shared/status-badge";
import type { DisciplineBan, DisciplineRules, Match, SportDef } from "@/types/api";

/**
 * Wrapped for the same reason the organizer's schedule is: the view reads the
 * query string, and useSearchParams() needs a Suspense boundary above it to
 * build.
 */
export default function OfficiatingEventPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full rounded-xl" />}>
      <OfficiatingEventView />
    </Suspense>
  );
}

function OfficiatingEventView() {
  const { id: eventId } = useParams<{ id: string }>();
  // Category in the URL, matchday in local state — exactly the split the
  // organizer's schedule makes, so a crew member who is sent a link lands on
  // the same category the sender was looking at.
  const { params: urlState, setParams } = useUrlState();
  const categoryId = urlState.get("category") ?? "";
  const [dateKey, setDateKey] = useState<string | null>(null);

  const detailQuery = useQuery({
    queryKey: ["officiating-event", eventId],
    queryFn: () => getOfficiatingEvent(eventId),
  });

  const event = detailQuery.data?.event;
  const categories = detailQuery.data?.categories ?? [];
  const assignment = detailQuery.data?.assignment;
  // Kickoffs are UTC instants; the venue's zone is what they mean.
  const tz = event?.timezone ?? "Asia/Jakarta";

  const selectedCategory =
    categories.find((c) => c.id === categoryId) ?? categories[0] ?? null;
  const catId = selectedCategory?.id;

  const matchesQuery = useQuery({
    queryKey: ["officiating-matches", eventId, catId],
    queryFn: () => getOfficiatingMatches(eventId, catId!),
    enabled: !!catId,
  });

  // Who is carrying cards, and which fixture that keeps them out of. The same
  // payload the organizer's schedule and the public results read, from the same
  // service — three readers of one tally, so the warning on a card cannot
  // disagree with the table it came from. Skipped for a sport that books nobody.
  //
  // Note the key shape: no org id, because a task account has no organization.
  // Its writers are the staff editors below, which invalidate the two-element
  // prefix.
  const disciplineQuery = useQuery({
    queryKey: ["officiating-discipline", eventId, catId],
    queryFn: () => getOfficiatingDiscipline(eventId, catId!),
    enabled: !!catId && tracksDiscipline(event?.sport),
  });

  const matches = matchesQuery.data ?? [];
  // Staff fill in the score sheet; referees read it. Both see the same schedule.
  const canScore = assignment?.kind === "staff";
  // The other half of the same branch. Presentation only, exactly like
  // `canScore`: `event.referee` answers 403 to staff whatever this renders.
  const canReview = assignment?.kind === "referee";
  const setBased = isSetBased(event);
  // A tie is scored partai by partai, and MatchResultService refuses a typed
  // scoreline for these categories outright (422). The card says so instead of
  // offering fields that cannot save.
  const rubberTie = !!selectedCategory?.uses_rubbers;
  // Branch on the engine, not the format key — a preset can be named anything.
  const engine = selectedCategory?.engine ?? null;
  const knockout = isKnockoutFormat(engine);
  const sections = buildMatchSections(
    matches,
    knockout,
    isDoubleElim(engine),
    isHybridFormat(engine),
  );

  // Only when it says something the section heading doesn't — same rule as the
  // organizer's schedule, so the two screens label a fixture identically.
  const phaseOf = (m: Match) =>
    m.group_name || m.stage === "knockout" || knockout || isThirdPlace(m) || isDecider(m)
      ? phaseLabel(m, matches, knockout)
      : undefined;

  const dateGroups = groupByDate(matches, tz);
  const activeDateKey =
    dateKey && dateGroups.some((g) => g.key === dateKey)
      ? dateKey
      : defaultDateKey(dateGroups, tz);
  const activeDateGroup = dateGroups.find((g) => g.key === activeDateKey);
  const daySections = sections
    .map(
      ([label, list]) =>
        [
          label,
          list
            .filter((m) => dateKeyOf(m.scheduled_at, tz) === activeDateKey)
            // Earliest kickoff first: the API orders by round, which is the
            // committee's order, not the order the day is actually worked.
            .sort((a, b) => {
              const ta = a.scheduled_at ? new Date(a.scheduled_at).getTime() : Infinity;
              const tb = b.scheduled_at ? new Date(b.scheduled_at).getTime() : Infinity;
              return ta - tb;
            }),
        ] as [string, Match[]],
    )
    .filter(([, list]) => list.length > 0);

  return (
    <EventTimezoneProvider timezone={tz}>
      <div>
        <PageHeader
          title={event?.name ?? "Jadwal Pertandingan"}
          description={
            assignment
              ? `Kamu bertugas sebagai ${assignment.role_display} di event ini.`
              : "Jadwal pertandingan event yang menugaskanmu."
          }
          backHref="/officiating"
          backLabel="Semua penugasan"
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
                // Each category has its own matchdays; the one picked here has
                // no meaning in the next.
                setParams({ category: key });
                setDateKey(null);
              }}
            />
          </div>
        )}

        {detailQuery.isLoading || matchesQuery.isLoading ? (
          <div className="grid gap-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-24 w-full rounded-xl" />
            ))}
          </div>
        ) : matches.length === 0 ? (
          <EmptyState
            icon={CalendarClock}
            title="Belum ada jadwal"
            // Nothing to offer but the fact: the crew cannot create fixtures,
            // and a button here would be a door that does not open.
            description="Panitia belum membuat jadwal untuk kategori ini. Cek lagi nanti atau hubungi panitia event."
          />
        ) : (
          <>
            <MatchDayTabs
              groups={dateGroups}
              activeKey={activeDateKey}
              onSelect={setDateKey}
            />

            <div className="mb-6 grid gap-1 border-b border-border pb-3">
              <h2
                className="text-base font-bold"
                style={{ fontFamily: "var(--font-display)" }}
              >
                {fullDateLabel(activeDateGroup?.iso ?? null, tz)}
              </h2>
              <p className="text-xs text-muted-foreground">
                {activeDateGroup?.list.length ?? 0} pertandingan
              </p>
            </div>

            <div className="grid gap-8">
              {daySections.map(([label, list]) => (
                <div key={label} className="grid gap-4">
                  <h3 className="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                    {label}
                  </h3>
                  {/* grid-cols-1 is load-bearing on phones: an implicit `auto`
                      track is sized by its widest child's min-content, so one
                      card that refuses to shrink drags the page wider than the
                      viewport. */}
                  <div className="grid grid-cols-1 items-start gap-3 xl:grid-cols-2">
                    {list.map((m) => (
                      <CrewMatchCard
                        key={m.id}
                        match={m}
                        phase={phaseOf(m)}
                        eventId={eventId}
                        canScore={canScore}
                        canReview={canReview}
                        setBased={setBased}
                        knockout={knockout || m.stage === "knockout" || isDecider(m)}
                        rubberTie={rubberTie}
                        bans={disciplineQuery.data?.matches?.[m.id] ?? []}
                        sport={event?.sport ?? null}
                        disciplineRules={disciplineQuery.data?.rules ?? null}
                      />
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    </EventTimezoneProvider>
  );
}

/** Team crest: the uploaded logo, or the same gradient fallback as everywhere else. */
function Crest({ name, logoUrl }: { name: string; logoUrl: string | null | undefined }) {
  if (logoUrl) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={logoUrl}
        alt=""
        className="h-7 w-7 shrink-0 rounded-full object-cover"
      />
    );
  }
  return (
    <span
      aria-hidden
      className="h-7 w-7 shrink-0 rounded-full"
      style={{ background: crestGradient(name) }}
    />
  );
}

/**
 * One fixture: the scoreline both halves of the crew read, and — for staff — the
 * controls that fill it in.
 *
 * Written here rather than reused, and the two candidates are worth naming.
 * `MatchCardHeader` is bound to the organizer's write controls — it takes an
 * `orgId` and renders MatchConfirmBar and MatchStatusActions, all of which post
 * to `organizations/{org}/…`, the exact API a task account is kept out of.
 * `PublicMatchCard` is the right shape but is styled entirely from
 * `app/(public)/event-shell.css`, which the dashboard shell does not load, so it
 * would render unstyled here; it is also a `<button>` needing an onClick, and
 * its `bans`/`sport`/`disciplineRules` are required-not-defaulted on purpose —
 * the same reason they are required here.
 *
 * What *is* reused is everything that writes: GoalScoreEditor, SetScoreEditor
 * and MatchStatsEditor are the organizer's own components, handed an officiating
 * gateway instead of a tenant-scoped one. The shootout rule, the assist ceiling
 * and the status-travels-with-the-click save are enforced by one service per
 * door on the server; a second copy of them on this screen is how the two doors
 * start refusing different things.
 *
 * `canScore`/`canReview` are the whole of the role branch: each half of the crew
 * gets the other's controls left out. That is presentation, not enforcement —
 * `event.staff` and `event.referee` each answer 403 to the other role whatever
 * this component renders.
 */
function CrewMatchCard({
  match: m,
  phase,
  eventId,
  canScore,
  canReview,
  setBased,
  knockout,
  rubberTie,
  bans,
  sport,
  disciplineRules,
}: {
  match: Match;
  phase?: string;
  eventId: string;
  /** Staff record results; referees read them. */
  canScore: boolean;
  /** Referees sign off the team sheets; staff read them. */
  canReview: boolean;
  setBased: boolean;
  /** A tie that must produce a winner — level scores go to penalties. */
  knockout: boolean;
  /** A team category whose result is its partai, not a typed scoreline. */
  rubberTie: boolean;
  bans: DisciplineBan[];
  sport: SportDef | null;
  disciplineRules: DisciplineRules | null;
}) {
  const tz = useEventTimezone();
  const [showStats, setShowStats] = useState(false);

  // A mutation rather than a query: it writes nothing, but it is fired by a
  // click and its whole result is a file plus, on a refusal, a message — the
  // shape useQuery is worst at.
  const sheet = useMutation({
    mutationFn: () => downloadOfficiatingLineupSheet(eventId, m.id),
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal mengunduh susunan pemain.").message),
  });
  const time = timeOf(m.scheduled_at, tz);
  const live = m.status === "ongoing";
  const hasScore = m.home_score !== null && m.away_score !== null;
  const done = m.status === "finished" && hasScore;
  // Nobody has lost at half time, so the dimming that marks a beaten side stays
  // tied to a finished result.
  const winner = done ? matchWinnerId(m) : null;
  const showScore = done || (live && hasScore);
  const sets = showScore && m.sets?.length ? m.sets : null;

  const side = (which: "home" | "away") => {
    const team = which === "home" ? m.home_team : m.away_team;
    const teamId = which === "home" ? m.home_team_id : m.away_team_id;
    const score = which === "home" ? m.home_score : m.away_score;
    const other = which === "home" ? "away" : "home";
    const name = team?.name ?? "TBD";

    return (
      <div
        className={cn(
          "flex items-center gap-2.5",
          winner && winner !== teamId && "opacity-55",
        )}
      >
        <Crest name={name} logoUrl={team?.logo_url} />
        <span className="min-w-0 flex-1 truncate text-sm font-semibold">{name}</span>
        {sets && (
          <span
            className="flex shrink-0 gap-1.5 tabular-nums"
            aria-label={`Skor set: ${sets.map((s) => s[which]).join(", ")}`}
          >
            {sets.map((s, i) => (
              <span
                key={i}
                className={cn(
                  "w-5 text-center text-xs",
                  s[which] > s[other]
                    ? "font-bold text-foreground"
                    : "text-muted-foreground",
                )}
              >
                {s[which]}
              </span>
            ))}
          </span>
        )}
        {showScore && (
          <span
            className={cn(
              "w-7 shrink-0 text-right text-base font-bold tabular-nums",
              live && "text-[var(--brand-600)]",
            )}
          >
            {score}
          </span>
        )}
      </div>
    );
  };

  // A tie's partai, under the two squad names: what the "3 – 0" is made of.
  // Unplayed partai are dropped rather than shown as blanks — a row with no
  // sets has not happened yet.
  const rubbers = (m.rubbers ?? []).filter(
    (r): r is typeof r & { sets: { home: number; away: number }[] } => !!r.sets?.length,
  );

  // Both sides seated, and the fixture still due to be played. A bracket slot
  // whose opponent is still TBD has nothing to score, and a cancelled fixture
  // has nothing to score any more — in both cases the card falls back to the
  // read-only shape the referee sees.
  const playable = !!m.home_team_id && !!m.away_team_id && m.status !== "cancelled";
  const scoring = canScore && playable;
  // A team sheet is handed in by a manager of a seated team, so a bracket slot
  // whose opponent is still TBD has nothing to review yet — the same gate the
  // scoring branch uses, for the same reason.
  const reviewing = canReview && playable;

  return (
    <Card className={cn("p-4", m.status === "cancelled" && "opacity-60")}>
      <div className="flex items-start gap-4">
        <div className="w-14 shrink-0">
          <div className="text-sm font-bold tabular-nums">{time ?? "TBD"}</div>
          {time && <div className="text-[11px] text-muted-foreground">{tzLabel(tz)}</div>}
        </div>

        <div className="min-w-0 flex-1 grid gap-2">
          {side("home")}
          {side("away")}

          {rubbers.length > 0 && (
            <div className="mt-1 grid gap-1 border-t border-border pt-2">
              {rubbers.map((r) => (
                <div
                  key={r.id}
                  className="flex items-center justify-between gap-3 text-xs text-muted-foreground"
                >
                  <span className="truncate">{r.label}</span>
                  <span className="flex shrink-0 gap-2 tabular-nums">
                    {r.sets.map((s, i) => (
                      <span key={i}>
                        <span className={cn(s.home > s.away && "font-bold text-foreground")}>
                          {s.home}
                        </span>
                        <span className="px-0.5">–</span>
                        <span className={cn(s.away > s.home && "font-bold text-foreground")}>
                          {s.away}
                        </span>
                      </span>
                    ))}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3 text-xs text-muted-foreground">
        <MatchStatusBadge match={m} />
        {phase && <Badge variant="outline">{phase}</Badge>}
        {wentToPenalties(m) ? (
          <span>
            Pen {m.home_penalty}–{m.away_penalty}
          </span>
        ) : (
          m.venue && <span>{m.venue}</span>
        )}
      </div>

      {bans.length > 0 && (
        <div className="mt-2">
          <MatchDisciplineNotice bans={bans} sport={sport} rules={disciplineRules} />
        </div>
      )}

      {/* The event id rides in the query string because every officiating
          endpoint is scoped by event — the personnel row that proves this
          account may be here at all is per-event, and the match route has no
          other way to say which one. */}
      {reviewing && (
        <div className="mt-3 flex justify-end border-t border-border pt-3">
          <Button asChild size="sm" variant="outline">
            <Link href={`/officiating/matches/${m.id}?event=${eventId}`}>
              <ClipboardCheck className="h-4 w-4" />
              Susunan pemain
            </Link>
          </Button>
        </div>
      )}

      {/* The print gate is the server's alone: it refuses (422) until the
          referee has signed off both sides, and the message it refuses with is
          the only thing that says which half is missing. Mirroring that rule
          here — hiding or disabling the button — would be a second reader of it,
          and the two would disagree the first time a sheet was approved in
          another tab. So the button is always offered, and the refusal is the
          answer. */}
      {scoring && (
        <div className="mt-3 flex justify-end border-t border-border pt-3">
          <Button
            size="sm"
            variant="outline"
            onClick={() => sheet.mutate()}
            disabled={sheet.isPending}
          >
            <Printer className="h-4 w-4" />
            {sheet.isPending ? "Menyiapkan…" : "Cetak susunan pemain"}
          </Button>
        </div>
      )}

      {scoring && (
        <div className="mt-3 border-t border-border pt-3">
          {rubberTie ? (
            // The result of a tie is its partai, and those are entered one by
            // one — the server refuses a typed scoreline for these categories
            // (422). Offering the fields anyway would be a save that always
            // fails.
            <p className="text-xs text-muted-foreground">
              Skor partai untuk kategori beregu diisi panitia. Hubungi panitia
              event untuk mengisikannya.
            </p>
          ) : setBased ? (
            <SetScoreEditor
              gateway={officiatingResultGateway(eventId, m.id)}
              match={m}
            />
          ) : (
            <GoalScoreEditor
              gateway={officiatingResultGateway(eventId, m.id)}
              match={m}
              knockout={knockout}
              actions={
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setShowStats((v) => !v)}
                  aria-label="Statistik pemain"
                  title="Statistik pemain"
                >
                  <Goal className="h-4 w-4" />
                </Button>
              }
            />
          )}

          {setBased && !rubberTie && (
            <div className="mt-2 flex justify-end">
              <Button size="sm" variant="ghost" onClick={() => setShowStats((v) => !v)}>
                <Goal className="h-4 w-4" />
                Statistik pemain
              </Button>
            </div>
          )}

          {/* Cards are written here, so the ban list above has to be refetched
              afterwards — the gateway carries that key. */}
          {showStats && !rubberTie && (
            <MatchStatsEditor
              gateway={officiatingStatsGateway(eventId, m.id)}
              match={m}
            />
          )}

          <p className="mt-3 text-xs text-muted-foreground">
            Hasil yang kamu simpan menunggu konfirmasi admin panitia sebelum
            masuk klasemen.
          </p>
        </div>
      )}
    </Card>
  );
}
