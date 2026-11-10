"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import {
  AlertCircle,
  Calendar,
  ClipboardList,
  Flag,
  QrCode,
  Settings2,
  Trophy,
  Users,
} from "lucide-react";

import { getMyTeams } from "@/lib/api/events";
import { getMyRaceEntries } from "@/lib/api/race";
import { rupiah } from "@/lib/labels";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RaceEntryStatusBadge, TeamStatusBadge } from "@/components/shared/status-badge";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

function fmtDate(d: string | null | undefined) {
  if (!d) return null;
  return new Date(d).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

/**
 * Everything this account registered for, in two sections.
 *
 * Two sections rather than one list, and that is the whole shape of this page:
 * a team and a fun run entry share no column worth sorting on. A team carries a
 * roster, a crest and an organizer still to approve it; an entry carries one
 * person, a price window and a QR for the start line. Interleaving them would
 * need a row that answers both, and a row that answers both says nothing about
 * either.
 *
 * A section with nothing in it renders nothing at all — a runner who never
 * managed a team is not owed an empty "Tim" card, and vice versa. The empty
 * state only appears when BOTH are empty, which is the one state that means
 * "you have not registered for anything".
 */
export default function ParticipantPage() {
  const { sportLabel } = useCatalog();
  const teamsQuery = useQuery({ queryKey: ["my-teams"], queryFn: getMyTeams });
  const entriesQuery = useQuery({ queryKey: ["my-race-entries"], queryFn: getMyRaceEntries });

  const teams = teamsQuery.data;
  const entries = entriesQuery.data;
  const approvedCount = teams?.filter((t) => t.status === "approved").length ?? 0;
  const paidEntries = entries?.filter((e) => e.status === "paid").length ?? 0;

  // Both have to be loaded before "nothing here" can be true: showing the empty
  // state while the other half is still in flight tells a runner they have no
  // registrations a moment before their three appear.
  const loading = teamsQuery.isLoading || entriesQuery.isLoading;
  const nothing =
    teamsQuery.isSuccess &&
    entriesQuery.isSuccess &&
    (teams?.length ?? 0) === 0 &&
    (entries?.length ?? 0) === 0;

  return (
    <div>
      <PageHeader
        title="Pendaftaran Saya"
        description="Pusat peserta — tim yang kamu daftarkan dan lomba yang kamu ikuti, beserta statusnya."
        actions={
          <Button asChild variant="outline">
            <Link href="/event">
              <Trophy className="h-4 w-4" />
              Jelajahi event
            </Link>
          </Button>
        }
      />

      {loading && (
        <div className="grid gap-3">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-[80px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {/* The tallies above the lists, each one counting its own section. Not
          summed into one number: "4 pendaftaran" over two lists leaves nobody
          able to check it against what is on screen. */}
      {!loading && !nothing && (
        <div className="mb-4 flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground">
          {(teams?.length ?? 0) > 0 && (
            <>
              <span>
                <span className="font-semibold text-foreground">{teams!.length}</span> tim terdaftar
              </span>
              <span>·</span>
              <span>
                <span className="font-semibold text-foreground">{approvedCount}</span> disetujui
              </span>
            </>
          )}
          {(teams?.length ?? 0) > 0 && (entries?.length ?? 0) > 0 && <span>·</span>}
          {(entries?.length ?? 0) > 0 && (
            <>
              <span>
                <span className="font-semibold text-foreground">{entries!.length}</span> pendaftaran
                lomba
              </span>
              <span>·</span>
              <span>
                <span className="font-semibold text-foreground">{paidEntries}</span> lunas
              </span>
            </>
          )}
        </div>
      )}

      {nothing && (
        <EmptyState
          icon={ClipboardList}
          title="Belum ada pendaftaran"
          description="Kamu belum mendaftarkan tim maupun mengikuti lomba. Temukan event dan daftar dari sana."
          action={
            <Button asChild variant="outline">
              <Link href="/event">Jelajahi event</Link>
            </Button>
          }
        />
      )}

      {(teams?.length ?? 0) > 0 && (
        <section className="mb-7">
          <h2
            className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"
            style={{ fontFamily: "var(--font-display)" }}
          >
            <Users className="h-4 w-4" />
            Tim
          </h2>
          <div className="grid gap-3">
            {teams!.map((team) => (
              // Stacks on a phone: side by side, the shrink-0 button squeezed
              // the text column to a few characters and the event name came
              // down in four lines.
              <Card
                key={team.id}
                className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:gap-4"
              >
                <div className="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                <span
                  className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]"
                  style={{ fontFamily: "var(--font-display)" }}
                >
                  {initials(team.name)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                      {team.name}
                    </span>
                    <TeamStatusBadge status={team.status} />
                    {team.payment_status === "unpaid" && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-[color-mix(in_srgb,var(--danger)_12%,transparent)] px-2 py-0.5 text-xs font-medium text-[var(--danger)]">
                        <AlertCircle className="h-3 w-3" />
                        Belum bayar {rupiah(team.payment_amount)}
                      </span>
                    )}
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                    {/* items-start + mt: a long event name wraps to two lines,
                        and a centred icon beside it floats in the gutter
                        looking like it belongs to neither. */}
                    <span className="flex min-w-0 items-start gap-1.5">
                      <Trophy className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      <span className="min-w-0">{team.event?.name ?? "Event"}</span>
                    </span>
                    {team.event?.sport_type && <span>{sportLabel(team.event.sport_type)}</span>}
                    <span className="inline-flex items-center gap-1.5">
                      <Users className="h-3.5 w-3.5" />
                      {team.players?.length ?? 0} pemain
                    </span>
                  </div>
                </div>
                </div>
                <Button
                  asChild
                  size="sm"
                  variant="outline"
                  className="w-full shrink-0 sm:w-auto"
                >
                  <Link href={`/participant/teams/${team.id}`}>
                    <Settings2 className="h-4 w-4" />
                    Kelola
                  </Link>
                </Button>
              </Card>
            ))}
          </div>
        </section>
      )}

      {(entries?.length ?? 0) > 0 && (
        <section>
          <h2
            className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"
            style={{ fontFamily: "var(--font-display)" }}
          >
            <Flag className="h-4 w-4" />
            Lomba
          </h2>
          <div className="grid gap-3">
            {entries!.map((entry) => {
              const date = fmtDate(entry.event?.start_date);

              return (
                <Card
                  key={entry.id}
                  className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:gap-4"
                >
                  <div className="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                  <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)]">
                    <Flag className="h-5 w-5" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                        {entry.event?.name ?? "Event"}
                      </span>
                      {/* `awaiting_verification` is derived, never a sixth
                          status value — the badge takes it as its own prop. */}
                      <RaceEntryStatusBadge
                        status={entry.status}
                        awaitingVerification={entry.awaiting_verification}
                      />
                      {/* Shown the moment it exists, which is settlement: the
                          code is minted by markPaid() and never before. Its
                          absence is the signal, so there is no `?? ""`
                          anywhere near it. */}
                      {entry.qr_code && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-[color-mix(in_srgb,var(--success)_12%,transparent)] px-2 py-0.5 text-xs font-medium text-[var(--success)]">
                          <QrCode className="h-3 w-3" />
                          {entry.checked_in_at ? "Sudah check-in" : "QR siap"}
                        </span>
                      )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                      <span className="inline-flex items-center gap-1.5">
                        <Flag className="h-3.5 w-3.5" />
                        {entry.category?.name ?? "Kategori"}
                      </span>
                      {date && (
                        <span className="inline-flex items-center gap-1.5">
                          <Calendar className="h-3.5 w-3.5" />
                          {date}
                        </span>
                      )}
                      {/* The gross, not `price`: that is what the runner was
                          actually charged, fees included. */}
                      <span>{entry.gross_amount > 0 ? rupiah(entry.gross_amount) : "Gratis"}</span>
                      {entry.bib_number && <span>BIB {entry.bib_number}</span>}
                    </div>
                  </div>
                  </div>
                  {/* The public proof page, not a dashboard twin of it: the
                      upload, the documents and the QR already live there, and a
                      runner who arrived from their email is looking at the same
                      screen this links to. */}
                  <Button asChild size="sm" variant="outline" className="w-full shrink-0 sm:w-auto">
                    <Link href={`/race-entries/${entry.id}`}>
                      <ClipboardList className="h-4 w-4" />
                      Buka bukti
                    </Link>
                  </Button>
                </Card>
              );
            })}
          </div>
        </section>
      )}
    </div>
  );
}
