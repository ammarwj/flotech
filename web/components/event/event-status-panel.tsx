"use client";

import { Check, CheckCircle2, DoorClosed, DoorOpen, PlayCircle, Rocket, XCircle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import type { EventStatus, SportEvent } from "@/types/api";

/**
 * The four phases of an event, in order.
 *
 * `registration_closed` is not a phase of its own — it is the registration
 * phase with the door shut, which is how organizers talk about it and why they
 * can move back and forth between the two. Giving it a stop of its own would
 * put a step on the track the event can walk backwards over. Which way the door
 * is currently set is told by the line underneath, not by relabelling the stop.
 */
const PHASES = [
  { label: "Draf", statuses: ["draft"] },
  { label: "Pendaftaran", statuses: ["open", "registration_closed"] },
  { label: "Berlangsung", statuses: ["ongoing"] },
  { label: "Selesai", statuses: ["finished"] },
] as const;

const phaseIndexOf = (status: EventStatus) =>
  PHASES.findIndex((p) => (p.statuses as readonly string[]).includes(status));

/** How each move is worded, and what it costs that can't be undone. */
const MOVES: Record<
  EventStatus,
  { label: string; icon: typeof Rocket; confirm?: string } | null
> = {
  draft: null, // nothing returns to draft
  open: { label: "Buka Pendaftaran", icon: DoorOpen },
  registration_closed: { label: "Tutup Pendaftaran", icon: DoorClosed },
  ongoing: { label: "Mulai Event", icon: PlayCircle },
  finished: {
    label: "Selesaikan Event",
    icon: CheckCircle2,
    confirm:
      "Selesaikan event ini?\n\nDana tertahan dari tiket & pendaftaran langsung dicairkan ke saldo, dan status tidak bisa dikembalikan lagi.",
  },
  cancelled: {
    label: "Batalkan Event",
    icon: XCircle,
    confirm:
      "Batalkan event ini?\n\nDana tertahan tidak akan dicairkan, dan status tidak bisa dikembalikan lagi.",
  },
};

/** The one move that carries the event forward, given where it stands. */
const NEXT_STEP: Partial<Record<EventStatus, EventStatus>> = {
  draft: "open",
  open: "registration_closed",
  registration_closed: "ongoing",
  ongoing: "finished",
};

function Track({ status }: { status: EventStatus }) {
  const current = phaseIndexOf(status);

  const last = PHASES.length - 1;

  return (
    // Equal fluid columns with the connector drawn as two halves inside each
    // stop. Fixed-width stops overflow a phone; this can't, and the halves keep
    // the line aligned to the node centre at every width.
    <ol className="flex">
      {PHASES.map((phase, i) => {
        const done = i < current;
        const here = i === current;
        const line = (reached: boolean) =>
          cn("absolute top-3 h-0.5 w-1/2 -translate-y-1/2", reached ? "bg-[var(--brand-600)]" : "bg-[var(--border)]");

        return (
          <li key={phase.label} className="relative flex min-w-0 flex-1 flex-col items-center gap-2">
            {i > 0 && <span aria-hidden className={cn(line(i <= current), "left-0")} />}
            {i < last && <span aria-hidden className={cn(line(i < current), "right-0")} />}

            {/* No step numbers: the order is already carried by the track and
                the names, so a digit would only add a glyph to read. */}
            <span
              aria-hidden
              className={cn(
                "relative z-10 grid h-6 w-6 place-items-center rounded-full transition-colors",
                done && "bg-[var(--brand-600)] text-white",
                here && "bg-[var(--brand-600)] ring-4 ring-[var(--tint)]",
                !done && !here && "border-2 border-[var(--border-strong)] bg-card"
              )}
            >
              {done && <Check className="h-3.5 w-3.5" strokeWidth={3} />}
            </span>
            <span
              className={cn(
                "text-balance px-1 text-center text-[11px] leading-tight sm:text-xs",
                here ? "font-semibold text-foreground" : "text-muted-foreground"
              )}
            >
              {phase.label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

/**
 * Where the event stands in its life, and the moves it can make from here.
 *
 * The buttons come from `next_statuses`, which the API derives from its own
 * transition table — a move can never be offered that the backend would refuse.
 */
export function EventStatusPanel({
  event,
  pending,
  onChange,
}: {
  event: SportEvent;
  pending: boolean;
  onChange: (status: EventStatus) => void;
}) {
  const next = event.next_statuses ?? [];
  const cancelled = event.status === "cancelled";

  const primary = NEXT_STEP[event.status];
  // Legal but rarely wanted: skipping a phase. Kept reachable, kept quiet.
  const shortcuts = next.filter((s) => s !== "cancelled" && s !== primary);
  // A draft is thrown away, not cancelled — offering both here and the header's
  // Hapus would be two buttons for one intention.
  const canCancel = next.includes("cancelled") && event.status !== "draft";

  const act = (status: EventStatus) => {
    const move = MOVES[status];
    if (move?.confirm && !window.confirm(move.confirm)) return;
    onChange(status);
  };

  const button = (status: EventStatus, variant: "default" | "ghost") => {
    const move = MOVES[status];
    if (!move) return null;
    // Draft is the only place "open" means publishing rather than reopening.
    const publishing = status === "open" && event.status === "draft";
    const Icon = publishing ? Rocket : move.icon;

    return (
      <Button
        key={status}
        variant={variant}
        size="sm"
        disabled={pending}
        onClick={() => act(status)}
        className={variant === "ghost" ? "text-muted-foreground" : undefined}
      >
        <Icon className="h-4 w-4" />
        {publishing ? "Publikasikan" : move.label}
      </Button>
    );
  };

  return (
    <Card className="mb-5 overflow-hidden">
      <div className="px-4 py-5 sm:px-6">
        {cancelled ? (
          <div className="flex items-center gap-2.5 text-[var(--danger)]">
            <XCircle className="h-5 w-5 shrink-0" />
            <span className="text-sm font-semibold">Event dibatalkan</span>
          </div>
        ) : (
          <Track status={event.status} />
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-border bg-[var(--surface-2)] px-4 py-2.5 sm:px-6">
        <p className="text-xs text-muted-foreground">{hintFor(event)}</p>

        {next.length > 0 && (
          <div className="flex w-full flex-wrap items-center justify-end gap-1 sm:w-auto">
            {/* Cancelling ends the event's life, so it is fenced off from the
                moves that carry it forward rather than sitting in their row. */}
            {canCancel && (
              <>
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={pending}
                  onClick={() => act("cancelled")}
                  className="text-muted-foreground hover:text-[var(--danger)]"
                >
                  Batalkan
                </Button>
                <span aria-hidden className="mx-1 h-5 w-px bg-border" />
              </>
            )}
            {shortcuts.map((status) => button(status, "ghost"))}
            {primary && next.includes(primary) && (
              <span className="ml-1">{button(primary, "default")}</span>
            )}
          </div>
        )}
      </div>
    </Card>
  );
}

/** One line telling the organizer what this status actually does right now. */
function hintFor(event: SportEvent): string {
  switch (event.status) {
    case "draft":
      return "Belum terlihat publik.";
    case "open":
      // The date window shuts registration on its own, so "Pendaftaran Dibuka"
      // alone misleads once a close date is set.
      return event.registration_close
        ? `Pendaftaran terbuka, tertutup sendiri ${dateLabel(event.registration_close)}.`
        : "Pendaftaran terbuka tanpa tanggal tutup.";
    case "registration_closed":
      return "Pendaftaran ditutup. Halaman event tetap tayang.";
    case "ongoing":
      return "Dana tiket & pendaftaran ditahan sampai event selesai.";
    case "finished":
      return "Dana tertahan sudah dicairkan ke saldo organizer.";
    case "cancelled":
      return "Dana tertahan tidak akan dicairkan.";
  }
}

function dateLabel(iso: string): string {
  return new Date(iso).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}
