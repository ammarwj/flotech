"use client";

import { useState } from "react";
import { CheckCircle2, Clock, Undo2, XCircle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  dialogConsequences,
} from "@/components/ui/dialog";
import { FieldError } from "@/components/ui/field-error";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { RaceEntry } from "@/types/api";

/**
 * The runner's side of a refund: ask for one, then see where it stands.
 *
 * Four states in one panel rather than four scattered conditions on the page,
 * because they are one conversation read in sequence — asked, waiting, granted,
 * refused — and the thing a runner wants to know is which of them they are in.
 *
 * **Whether the button may be shown is `entry.can_request_refund`, the SERVER's
 * answer.** Reassembling it here from `status`/`checked_in_at`/`refund_pending`
 * would be two readers of one four-part rule, and the drift shows up as a button
 * that submits into a 422 — the `effective_payment_method` lesson. The component
 * never recomputes it.
 *
 * The panel renders nothing at all when there is no button to offer and no
 * request to report: a card explaining that refunds exist, on an entry that
 * cannot have one, is an announcement about a door that is not there — the same
 * call `online_registration_enabled` makes on the public event page.
 */
export function RefundRequestPanel({
  entry,
  pending,
  onSubmit,
}: {
  entry: RaceEntry;
  pending: boolean;
  onSubmit: (reason: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);

  const refunded = entry.status === "refunded";
  const rejected = !!entry.refund_decided_at && !refunded;

  // Where the money actually is, which is the one thing this panel could not
  // say before: a refund is granted instantly, but the transfer out is manual
  // and runs through two hands. The generic sentence stays the fallback for a
  // refund that owes no transfer at all — a free entry, the manual rail, or a
  // credit reversed before it was ever released.
  const transferNote = (() => {
    switch (entry.refund_transfer?.stage) {
      case "queued":
        return "Dananya sedang diproses flo-event untuk dikirim ke penyelenggara, lalu diteruskan ke kamu.";
      case "with_organizer":
        return "Dananya sudah sampai ke penyelenggara dan sedang menunggu diteruskan ke kamu.";
      case "forwarded":
        return "Penyelenggara sudah menandai dananya diteruskan ke kamu. Hubungi mereka kalau belum masuk dalam beberapa hari kerja.";
      default:
        return "Dananya ditransfer manual, jadi tidak langsung masuk. Hubungi penyelenggara kalau belum diterima dalam beberapa hari kerja.";
    }
  })();

  // Nothing asked, nothing askable, nothing refunded — the panel is not owed
  // to this entry.
  //
  // `refunded` is load-bearing in that test, not belt-and-braces: a refund the
  // organizer issued on their own has `refund_requested` false (nobody asked)
  // AND `can_request_refund` false (it is no longer paid), so without it the
  // one runner who most needs an explanation — money came back on a
  // registration they never queried — is the one shown nothing at all.
  if (!refunded && !entry.refund_requested && !entry.can_request_refund) return null;

  const submit = () => {
    const text = reason.trim();

    // Checked here as well as on the server because an empty textarea is the
    // one refusal the runner can fix without a round trip; the server's own
    // `required` is still what enforces it.
    if (text === "") {
      setError("Tuliskan alasan pengajuan refund.");
      return;
    }

    setError(null);
    onSubmit(text);
    setOpen(false);
    setReason("");
  };

  return (
    <>
      {/* ---- Granted ----
          Two sentences for one outcome, told apart by `refund_requested`: a
          refund the organizer issued on their own — a duplicate payment, most
          often — was never asked for, so "disetujui" describes an approval of
          nothing. That branch prints the reason because it is the only thing on
          screen explaining why money came back on a registration the runner
          thought was fine; the asked-for one does not, since they wrote it. */}
      {refunded && entry.refund_requested && (
        <StatusCard
          tone="success"
          icon={CheckCircle2}
          title="Refund disetujui"
          body={transferNote}
          at={entry.refund_decided_at}
        />
      )}

      {refunded && !entry.refund_requested && (
        <StatusCard
          tone="success"
          icon={CheckCircle2}
          title="Pendaftaran ini direfund penyelenggara"
          body={`Alasannya: "${entry.refund_reason ?? "—"}". ${transferNote}`}
          at={entry.refund_decided_at}
        />
      )}

      {/* ---- Waiting ---- */}
      {entry.refund_pending && (
        <StatusCard
          tone="warning"
          icon={Clock}
          title="Pengajuan refund menunggu keputusan"
          body={`Alasan yang kamu kirim: "${entry.refund_reason ?? "—"}"`}
          at={entry.refund_requested_at}
        />
      )}

      {/* ---- Refused ----
          The reason is the point of this block, which is why it is the body and
          not a footnote: a refusal with nothing to read is worse for the person
          reading it than no feature at all. The button below it is still
          offered — a refusal is a verdict on that request, not on the entry. */}
      {rejected && (
        <StatusCard
          tone="danger"
          icon={XCircle}
          title="Pengajuan refund ditolak"
          body={entry.refund_rejected_reason || "Penyelenggara tidak memberikan alasan."}
          at={entry.refund_decided_at}
        />
      )}

      {/* ---- Ask ----
          A plain link-weight button, not a primary one: this is the exit from a
          registration, and giving it the same weight as "Bayar sekarang" puts
          it in the path of somebody who only came to check their BIB. */}
      {entry.can_request_refund && (
        <div className="mb-6 text-center">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setOpen(true)}
            className="text-muted-foreground"
          >
            <Undo2 className="mr-1.5 h-3.5 w-3.5" />
            {entry.refund_requested ? "Ajukan refund lagi" : "Ajukan refund"}
          </Button>
        </div>
      )}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader
            icon={Undo2}
            title="Ajukan refund"
            description="Penyelenggara yang memutuskan. Tuliskan alasannya — itu yang mereka baca."
          />
          <DialogBody>
            <div>
              <Label htmlFor="refund-reason">Alasan pengajuan</Label>
              <Textarea
                id="refund-reason"
                rows={4}
                value={reason}
                onChange={(e) => {
                  setReason(e.target.value);
                  if (error) setError(null);
                }}
                maxLength={500}
                aria-invalid={!!error}
                placeholder="Misalnya: cedera dan tidak bisa ikut lomba, atau jadwalnya bentrok."
              />
              <FieldError message={error ?? undefined} />
            </div>

            {/* Said BEFORE the click, not only in the toast after it: the two
                things a runner gets wrong about this are thinking it is
                automatic and thinking it is instant. */}
            <p className={dialogConsequences.default}>
              Penyelenggara yang menyetujui, dan dananya ditransfer manual.
              Pendaftaranmu tetap aktif selama menunggu.
            </p>
          </DialogBody>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button type="button" onClick={submit} disabled={pending}>
              {pending ? "Mengirim…" : "Kirim pengajuan"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

/**
 * One state of the conversation: a tinted card, an icon, and when it happened.
 *
 * The three tones are the three outcomes, and the icon differs along with the
 * colour — which is what keeps them apart for somebody who cannot tell the
 * colours apart. The same shape as the status banner above it on the page, so
 * the two read as one column rather than two designs.
 */
function StatusCard({
  tone,
  icon: Icon,
  title,
  body,
  at,
}: {
  tone: "success" | "warning" | "danger";
  icon: typeof Clock;
  title: string;
  body: string;
  at: string | null;
}) {
  const color = `var(--${tone})`;

  return (
    <Card
      className="mb-6 flex items-start gap-3 p-5"
      style={{
        borderColor: color,
        background: `color-mix(in srgb, ${color} 8%, transparent)`,
      }}
    >
      <Icon className="mt-0.5 h-6 w-6 shrink-0" style={{ color }} />
      <div className="min-w-0">
        <h2 className="text-sm font-bold" style={{ fontFamily: "var(--font-display)" }}>
          {title}
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">{body}</p>
        {at && (
          <p className="mt-1.5 text-xs text-muted-foreground">
            {new Date(at).toLocaleString("id-ID", {
              day: "numeric",
              month: "long",
              year: "numeric",
              hour: "2-digit",
              minute: "2-digit",
            })}
          </p>
        )}
      </div>
    </Card>
  );
}
