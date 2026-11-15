"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import {
  AlertCircle,
  ArrowLeftRight,
  Calendar,
  ClipboardList,
  Flag,
  QrCode,
  Settings2,
  Trophy,
  Undo2,
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
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogBody, DialogContent, DialogHeader } from "@/components/ui/dialog";
// Aliased: `QrCode` is already the lucide icon in this file, and the renderer
// and its icon colliding is the kind of shadowing that compiles and then draws
// the wrong thing.
import { QrCode as QrCode2 } from "@/components/event/qr-code";
import { CategoryTransferPanel } from "@/components/race/category-transfer-panel";
import { RefundRequestPanel } from "@/components/payment/refund-request-panel";
import { useRaceEntryActions } from "@/lib/hooks/use-race-entry-actions";
import type { RaceEntry } from "@/types/api";

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

  /**
   * Which entry each overlay is about, rather than a boolean per dialog.
   *
   * The row is the subject here, unlike on the proof page where there is only
   * ever one entry — so a bare `open` flag would need a second piece of state
   * saying which row it belongs to, and the two could disagree. One dialog is
   * mounted per purpose and re-seeded by `key`: mounting one per row to show at
   * most one is waste, and without the key the second entry opened would be
   * showing the first one's answers.
   */
  const [qrEntry, setQrEntry] = useState<RaceEntry | null>(null);
  const [transferEntry, setTransferEntry] = useState<RaceEntry | null>(null);
  const [refundEntry, setRefundEntry] = useState<RaceEntry | null>(null);

  // One hook for both actions, shared with the proof page — see it for why the
  // invalidation is the part worth not writing twice.
  const actions = useRaceEntryActions(transferEntry?.id ?? refundEntry?.id ?? null);

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
                  // `sm:items-start`, not centre: the action group is now up to
                  // four controls that wrap on a narrow card, and centring a
                  // two-line block against a two-line block leaves both
                  // floating with nothing aligned to anything.
                  // Two stacked rows, not a side-by-side — and this was tried
                  // the other way first. With four controls beside it the title
                  // block was squeezed to about 250px at a 1100px viewport, so
                  // the event name, the badges and the meta line all wrapped
                  // and the price landed alone on a third line. A row of
                  // actions under the facts costs one line and always reads the
                  // same, at every width.
                  className="flex flex-col gap-3 p-4"
                >
                  <div className="flex min-w-0 items-start gap-3">
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
                      {/* `paid_total` where the server sent it, falling back to
                          the entry's own gross — never `price`, which leaves
                          out the fees the runner was actually charged.

                          The fallback is not belt-and-braces: `paid_total` is
                          `whenLoaded`, so a surface that does not eager-load the
                          moves genuinely has no total to print, and `??` is what
                          keeps this card right on both. Where they differ the
                          entry moved category, and `gross_amount` is its own
                          FROZEN bill — printing it there shows the old price
                          beside the new category, which is exactly the
                          contradiction this reads around. */}
                      <span>
                        {(entry.paid_total ?? entry.gross_amount) > 0
                          ? rupiah(entry.paid_total ?? entry.gross_amount)
                          : "Gratis"}
                      </span>
                      {entry.bib_number && <span>BIB {entry.bib_number}</span>}
                    </div>
                  </div>
                  </div>
                  {/* The row's actions: the QR, the detail page, and the two
                      things a runner can do to the registration itself.

                      The QR is a DIALOG and not a link, because it is the one
                      thing on this page somebody opens while standing in front
                      of somebody else — a navigation there costs a page load
                      and a way back. Everything else is still the proof page:
                      the e-BIB, the documents, the fee breakdown and any open
                      move live there, and this list has nowhere to put a bank
                      account or an upload field.

                      "Lihat detail" and not "Buka bukti", which named that page
                      when a receipt was all it held. It now carries all of the
                      above, so the old label promises one document and hides
                      the rest — and over an entry still awaiting payment it
                      offers proof of something that has not happened. Sibling
                      of "Kelola" on the team card above: the pair names what
                      each destination lets you do. */}
                  {/* Its own row under the facts, divided by a rule: the two
                      outlined controls open things to READ, the two ghost ones
                      change the registration, and the group as a whole answers
                      a different question from the line above it. */}
                  <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                    {entry.qr_code && (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setQrEntry(entry)}
                      >
                        <QrCode className="h-4 w-4" />
                        QR
                      </Button>
                    )}
                    <Button asChild size="sm" variant="outline">
                      <Link href={`/race-entries/${entry.id}`}>
                        <ClipboardList className="h-4 w-4" />
                        Lihat detail
                      </Link>
                    </Button>
                    {/* Ghost weight, and both gated on the SERVER's own answer
                        — never rebuilt from `status`/`checked_in_at`, which is
                        the `effective_payment_method` bug in another shape.
                        An entry mid-move answers false to both, and the card
                        says so rather than offering a button that 422s. */}
                    {entry.can_transfer_category && (
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-muted-foreground"
                        onClick={() => setTransferEntry(entry)}
                      >
                        <ArrowLeftRight className="h-4 w-4" />
                        Pindah
                      </Button>
                    )}
                    {entry.can_request_refund && (
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-muted-foreground"
                        onClick={() => setRefundEntry(entry)}
                      >
                        <Undo2 className="h-4 w-4" />
                        Refund
                      </Button>
                    )}
                  </div>
                </Card>
              );
            })}
          </div>
        </section>
      )}

      {/* ---- The three overlays ----

          One of each, re-seeded by `key` so opening a second entry does not
          show the first one's state — the same call RaceEntryEditDialog makes.
          They sit outside the list for the same reason: mounting three per row
          to show at most one is waste.

          The two panels run CONTROLLED, which also suppresses their own trigger
          buttons: the triggers are in the row. See either component. */}
      <Dialog open={!!qrEntry} onOpenChange={(open) => !open && setQrEntry(null)}>
        <DialogContent>
          <DialogHeader
            icon={QrCode}
            title="QR Check-in"
            description="Tunjukkan QR ini di meja registrasi saat pengambilan race pack."
          />
          <DialogBody>
            {qrEntry?.qr_code && (
              <div className="text-center">
                <div className="flex justify-center">
                  <QrCode2 value={qrEntry.qr_code} size={220} />
                </div>
                {/* The raw code, for a desk whose scanner is down and has to
                    type it in — the same fallback the proof page carries. */}
                <p className="mt-3 break-all text-xs text-muted-foreground">
                  {qrEntry.qr_code}
                </p>
                <p className="mt-3 text-sm">
                  {qrEntry.category?.name ?? "—"}
                  {qrEntry.bib_number ? ` · BIB ${qrEntry.bib_number}` : ""}
                </p>
                {qrEntry.checked_in_at && (
                  <Badge variant="success" className="mt-3">
                    Sudah check-in
                  </Badge>
                )}
              </div>
            )}
          </DialogBody>
        </DialogContent>
      </Dialog>

      {transferEntry && (
        <CategoryTransferPanel
          key={transferEntry.id}
          entry={transferEntry}
          pending={actions.transfer.isPending}
          // The id goes WITH the call, not read from state inside the hook:
          // this closes in the same tick, and the mutation would otherwise run
          // against the already-cleared row. See `idFor()`.
          onSubmit={(input) => actions.transfer.mutate({ ...input, entryId: transferEntry.id })}
          dialogOnly
          open
          onOpenChange={(open) => !open && setTransferEntry(null)}
        />
      )}

      {refundEntry && (
        <RefundRequestPanel
          key={refundEntry.id}
          entry={refundEntry}
          pending={actions.refund.isPending}
          onSubmit={(reason) => actions.refund.mutate({ entryId: refundEntry.id, reason })}
          dialogOnly
          open
          onOpenChange={(open) => !open && setRefundEntry(null)}
        />
      )}
    </div>
  );
}
