"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowLeftRight, CheckCircle2, Clock, Download, Flag, Loader2, Undo2 } from "lucide-react";

import {
  cancelRaceTransfer,
  getRaceEntry,
  payRaceTransfer,
  requestRaceEntryRefund,
  startRaceTransfer,
  submitRaceEntryProof,
  submitRaceTransferProof,
} from "@/lib/api/race";
import { downloadMyBib } from "@/lib/api/bibs";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import { Card } from "@/components/ui/card";
import { QrCode } from "@/components/event/qr-code";
import { Badge } from "@/components/ui/badge";
import { ManualTransferPanel } from "@/components/payment/manual-transfer-panel";
import { RefundRequestPanel } from "@/components/payment/refund-request-panel";
import { CategoryTransferPanel } from "@/components/race/category-transfer-panel";
import { ParticipantDocumentButtons } from "@/components/payment/document-buttons";
import { CopyLinkButton } from "@/components/shared/copy-link-button";
import { rupiah } from "@/lib/labels";
import "../../event-shell.css";

function fmtDate(d: string | null | undefined) {
  if (!d) return "Tanggal menyusul";
  return new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" });
}

/**
 * Where a guest lands after signing in, encoded once.
 *
 * `/participant` and not this page: the dashboard is the thing they were sent
 * to log in *for*, and it carries this entry among the rest. Encoded because
 * `safeNext()` on the other side only honours same-site paths.
 */
const PARTICIPANT_NEXT = encodeURIComponent("/participant");

/**
 * The runner's proof of entry — this phase's answer to "what do I show on race
 * day".
 *
 * Public, addressed by the entry's unguessable id, for the reason the e-ticket
 * page is: a runner may never have signed up, and the receipt reached from here
 * is their only proof. Putting a login in front of it would lock out exactly
 * the people who need it.
 *
 * Phase two adds a QR to this page. The layout already leaves it the room the
 * e-ticket page gives its codes, and nothing here has to change shape for it —
 * `qr_code` simply stops being null.
 */
export default function RaceEntryPage() {
  const params = useParams<{ entryId: string }>();
  const qc = useQueryClient();

  // Both dialogs are opened from one row in the detail card below, so the page
  // owns their open state rather than each panel owning its own — see the
  // `controlled` note on either component.
  const [refundOpen, setRefundOpen] = useState(false);
  const [transferOpen, setTransferOpen] = useState(false);

  // Public route, so nothing has restored the session yet: the access token
  // lives in memory and is gone after every navigation into a public page.
  // Never redirects — a guest is a legitimate visitor here, which is the whole
  // reason this page has no login in front of it.
  const { ready: sessionReady, isAuthenticated } = useOptionalSession();

  const query = useQuery({
    queryKey: ["race-entry", params.entryId],
    queryFn: () => getRaceEntry(params.entryId),
    retry: false,
    /**
     * Poll while awaiting payment so the receipt and the QR unlock once
     * settled — for a manual entry that is when the organizer approves the
     * uploaded proof.
     *
     * Stops once the entry is no longer pending. Deliberately NOT extended to
     * watch for `checked_in_at`: the runner is standing at the desk when that
     * flips, looking at the staff rather than at their phone, and polling every
     * paid entry forever would have every runner's device hammering this
     * endpoint through the whole race.
     */
    refetchInterval: (q) => (q.state.data?.status === "pending" ? 4000 : false),
  });

  const proof = useMutation({
    mutationFn: (proofUrl: string) => submitRaceEntryProof(params.entryId, proofUrl),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success("Bukti terkirim", {
        description: "Menunggu verifikasi penyelenggara.",
      });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengirim bukti.").message),
  });

  /**
   * Asking for the fee back.
   *
   * A toast and nothing inline, unlike the forms that bind 422s to fields: the
   * only field here is the reason, the dialog validates it before sending, and
   * every other refusal is about the entry rather than about what was typed —
   * "sudah check-in", "masih menunggu keputusan". Those are sentences to read,
   * not a field to correct.
   */
  const refund = useMutation({
    mutationFn: (reason: string) => requestRaceEntryRefund(params.entryId, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success("Pengajuan terkirim", {
        description: "Menunggu keputusan penyelenggara.",
      });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal mengirim pengajuan refund.").message),
  });

  /**
   * Moving to another category.
   *
   * A paid top-up hands off to Midtrans; everything else stays on this page —
   * an even or downward move is already applied when the response lands, and a
   * manual one uploads its receipt in the panel below. One destination either
   * way, the same call the registration form makes.
   *
   * `mock` is checked before `redirect_url` for the reason written in
   * `checkoutOutcome()`: a Midtrans error also returns a null url, so the two
   * must not be read as one signal.
   */
  const transfer = useMutation({
    mutationFn: (input: { categoryId: string; reason: string | null; channel: string | null }) =>
      startRaceTransfer(params.entryId, {
        race_category_id: input.categoryId,
        reason: input.reason,
        payment_channel: input.channel,
      }),
    onSuccess: (res) => {
      if (!res.mock && res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }

      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      // The options are now stale in two ways — the quota moved and this entry
      // has an open move — so the next open of the dialog must not reuse them.
      qc.invalidateQueries({ queryKey: ["race-transfer-options", params.entryId] });
      toast.success(
        res.transfer.status === "paid" ? "Kategori berhasil dipindah" : "Pindah kategori dibuat",
        {
          description:
            res.transfer.status === "paid"
              ? "Nomor BIB kamu diterbitkan ulang mengikuti kategori baru."
              : "Selesaikan pembayaran selisihnya agar kategorinya berpindah.",
        }
      );
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memindahkan kategori.").message),
  });

  /**
   * Finishing a move that was started and left unpaid.
   *
   * The way out of a dead end: a Snap token expires after about a day, so the
   * tab the runner closed cannot be returned to, and the sweep that would
   * release the place their open move is holding only reaches the manual rail.
   *
   * The response decides where they go, never the state this page was holding:
   * a gateway that went down since lands this on the manual rail, and then
   * staying put is correct — the upload panel below renders for it.
   */
  const payTransfer = useMutation({
    mutationFn: ({ transferId, channel }: { transferId: string; channel: string }) =>
      payRaceTransfer(transferId, channel),
    onSuccess: (res) => {
      if (res.payment_method === "gateway" && !res.mock && res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }

      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success(
        res.transfer.status === "paid" ? "Kategori berhasil dipindah" : "Lanjutkan pembayaran",
        {
          description:
            res.payment_method === "manual"
              ? "Pembayaran online sedang tidak tersedia. Transfer manual sesuai panel di bawah."
              : undefined,
        }
      );
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuka pembayaran.").message),
  });

  /**
   * Giving up on a move. Releases the place it was holding in the target
   * category; the registration itself never left its original one.
   */
  const cancelTransfer = useMutation({
    mutationFn: (transferId: string) => cancelRaceTransfer(transferId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      // The quota just moved, so a list fetched before this is wrong about
      // which categories have room.
      qc.invalidateQueries({ queryKey: ["race-transfer-options", params.entryId] });
      toast.success("Pindah kategori dibatalkan", {
        description: "Pendaftaranmu tetap di kategori sekarang.",
      });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membatalkan.").message),
  });

  /**
   * The receipt for a manual top-up.
   *
   * The transfer's id travels as the mutation ARGUMENT rather than being read
   * off the query at call time: the page polls, so the row could be replaced
   * between the click and the request, and the id that was on screen is the one
   * the runner is paying for. Keyed on the transfer and not the entry because
   * the transfer is the row being paid for.
   */
  const transferProof = useMutation({
    mutationFn: ({ transferId, proofUrl }: { transferId: string; proofUrl: string }) =>
      submitRaceTransferProof(transferId, proofUrl),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success("Bukti terkirim", { description: "Menunggu verifikasi penyelenggara." });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengirim bukti.").message),
  });

  /**
   * The runner's own e-BIB.
   *
   * Through the API client with a blob response, never a plain `<a href>`: the
   * access token lives in memory, and a direct link would 401 for a signed-in
   * runner while working for a guest — the worse of the two failures, because
   * it looks like a bug in their account.
   */
  const downloadBibMutation = useMutation({
    mutationFn: () => downloadMyBib(params.entryId),
    onError: (err) => toast.error(parseApiError(err, "Gagal mengunduh e-BIB.").message),
  });

  if (query.isLoading) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center", color: "var(--text-muted)" }}>
        Memuat pendaftaran…
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center" }}>
        <h1 className="section-title">Pendaftaran tidak ditemukan</h1>
        <p className="section-sub">Periksa kembali tautan bukti pendaftaranmu.</p>
        <Link href="/" className="btn btn-primary btn-lg" style={{ marginTop: 24 }}>
          Ke beranda
        </Link>
      </div>
    );
  }

  const entry = query.data;
  const paid = entry.status === "paid";
  const manual = entry.payment_method === "manual" && entry.status === "pending";
  // A category top-up still owed on the manual rail. Read off `open_transfer`
  // rather than from the entry's own payment fields: the entry is fully paid,
  // and the rail of the move is its own — an entry bought on the gateway can be
  // topped up by hand if the gateway went down in between.
  const manualTopUp =
    entry.open_transfer?.payment_method === "manual" &&
    entry.open_transfer.status === "pending"
      ? entry.open_transfer
      : null;
  // `refunded` belongs here and not with the pending states: before it was
  // added, a refunded entry fell through to the warning banner and read
  // "Menunggu pembayaran · Selesaikan pembayaran agar pendaftaran aktif" over a
  // registration whose money had already gone back. It is grouped with the two
  // cancelled kinds because the consequence is the same — there is no place at
  // the start line — and the sentence below still names which of the three it
  // is, because they differ in what the runner should do next.
  const dead =
    entry.status === "cancelled" ||
    entry.status === "expired" ||
    entry.status === "refunded";

  const rows: [string, string][] = [
    ["Event", entry.event?.name ?? "—"],
    ["Tanggal", fmtDate(entry.event?.start_date)],
    ["Lokasi", entry.event?.location_name || "—"],
    ["Kategori", entry.category?.name ?? "—"],
    // Beside the price, because it is what makes the price checkable: the same
    // category costs three different amounts over a sale.
    ["Periode", entry.period_name],
    ["Biaya pendaftaran", rupiah(entry.price)],
  ];

  // Split, never lumped: one combined "Biaya layanan" row makes the rate
  // impossible to check against what the picker quoted — same reasoning as the
  // e-ticket page.
  if (entry.service_fee > 0) rows.push(["Biaya layanan", rupiah(entry.service_fee)]);
  if (entry.gateway_fee > 0) {
    const taxed = entry.gateway_tax > 0;
    rows.push([
      taxed ? "Biaya payment gateway" : "Biaya pembayaran",
      rupiah(entry.gateway_fee - entry.gateway_tax),
    ]);
    if (taxed) rows.push(["PPN", rupiah(entry.gateway_tax)]);
  }
  rows.push(["Total", rupiah(entry.gross_amount)]);

  return (
    <div className="container" style={{ paddingBlock: 48, maxWidth: 640 }}>
      {/* Status banner */}
      <Card
        className="mb-6 flex items-center gap-3 p-5"
        style={{
          borderColor: dead ? "var(--danger)" : paid ? "var(--success)" : "var(--warning)",
          background: `color-mix(in srgb, ${
            dead ? "var(--danger)" : paid ? "var(--success)" : "var(--warning)"
          } 8%, transparent)`,
        }}
      >
        {paid ? (
          <CheckCircle2 className="h-8 w-8 shrink-0 text-[var(--success)]" />
        ) : dead ? (
          <Flag className="h-8 w-8 shrink-0 text-[var(--danger)]" />
        ) : (
          <Clock className="h-8 w-8 shrink-0 text-[var(--warning)]" />
        )}
        <div>
          <h1 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {paid
              ? "Pendaftaran berhasil 🎉"
              : entry.status === "refunded"
                ? "Pendaftaran direfund"
                : dead
                  ? "Pendaftaran dibatalkan"
                  : "Menunggu pembayaran"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {paid
              ? "Unduh kwitansi di bawah dan tunjukkan saat pengambilan race pack di hari H."
              : dead
                ? entry.status === "expired"
                  ? "Tenggat pembayaran sudah lewat. Daftar ulang kalau kuotanya masih ada."
                  : entry.status === "refunded"
                    ? "Biaya pendaftaranmu dikembalikan dan tempatnya sudah dilepas. Detail pengembalian dana ada di bawah."
                    : "Pendaftaran ini sudah dibatalkan."
                : "Selesaikan pembayaran agar pendaftaran aktif. Halaman ini akan diperbarui otomatis."}
          </p>
        </div>
      </Card>

      {manual && (
        <ManualTransferPanel
          bankAccount={entry.bank_account ?? null}
          amount={entry.price}
          deadlineAt={entry.payment_deadline_at}
          awaitingVerification={entry.awaiting_verification}
          rejectedReason={entry.rejected_reason}
          pending={proof.isPending}
          onSubmit={(url) => proof.mutate(url)}
        />
      )}

      {/* The refund conversation. Above the detail card rather than below it,
          with the status banner: it is news, and news belongs where the eye
          already is. Renders nothing when there is neither a request to report
          nor a button to offer — see the component. */}
      <RefundRequestPanel
        entry={entry}
        pending={refund.isPending}
        onSubmit={(reason) => refund.mutate(reason)}
        open={refundOpen}
        onOpenChange={setRefundOpen}
      />

      {/* Moving category, above the detail card for the same reason the refund
          panel is: it is news. Renders nothing when there is neither an open
          move nor a button to offer — see the component.

          Both panels run CONTROLLED, which also suppresses their own triggers:
          the two buttons live together in the detail card below. Stacked as
          each panel's own centred row they took a full line each and read as
          two unrelated announcements between the banner and the details — which
          is exactly what they are not. */}
      <CategoryTransferPanel
        entry={entry}
        onSubmit={(input) => transfer.mutate(input)}
        onPay={(transferId, channel) => payTransfer.mutate({ transferId, channel })}
        onCancel={(transferId) => cancelTransfer.mutate(transferId)}
        pending={transfer.isPending || payTransfer.isPending || cancelTransfer.isPending}
        open={transferOpen}
        onOpenChange={setTransferOpen}
      />

      {/* Where to transfer a manual top-up. The same panel the entry's own
          manual payment uses, pointed at the transfer's amount and id: a
          second upload form would be two readers of one flow, and the one
          thing that differs here is which row is being paid for. Only while
          the top-up is genuinely owed — a downward or even move never has one. */}
      {manualTopUp && (
        <ManualTransferPanel
          bankAccount={manualTopUp.bank_account}
          amount={manualTopUp.gross_amount}
          deadlineAt={manualTopUp.payment_deadline_at}
          awaitingVerification={manualTopUp.awaiting_verification}
          rejectedReason={manualTopUp.rejected_reason}
          pending={transferProof.isPending}
          onSubmit={(url) =>
            transferProof.mutate({ transferId: manualTopUp.id, proofUrl: url })
          }
        />
      )}

      <Card className="mb-6 p-5">
        <h2 className="mb-3 text-base font-semibold" style={{ fontFamily: "var(--font-display)" }}>
          {entry.full_name}
        </h2>
        <dl className="space-y-2 text-sm">
          {rows.map(([label, value], i) => (
            <div
              key={label}
              className="flex items-baseline justify-between gap-4"
              style={
                i === rows.length - 1
                  ? { borderTop: "1px solid var(--border)", paddingTop: 8, fontWeight: 700 }
                  : undefined
              }
            >
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="text-right">{value}</dd>
            </div>
          ))}
          <div className="flex items-baseline justify-between gap-4">
            <dt className="text-muted-foreground">Status</dt>
            <dd>
              <Badge variant={paid ? "success" : dead ? "danger" : "warning"}>
                {entry.awaiting_verification ? "Menunggu verifikasi" : entry.status}
              </Badge>
            </dd>
          </div>
        </dl>

        <div className="mt-4 flex flex-wrap gap-2">
          <CopyLinkButton path={`/race-entries/${entry.id}`} />
          {/* The only place these are reachable: a runner may never have signed
              up, so there is no dashboard to put them in. `hasReceipt` rather
              than a paid flag — a free entry is settled but never issued one,
              and the button would 404. */}
          <ParticipantDocumentButtons
            subject={{ kind: "race-entry", id: entry.id }}
            hasInvoice={!!entry.invoice_number}
            hasReceipt={!!entry.receipt_number}
          />
        </div>

        {/* The two ways out of this registration, on one row below the
            documents and separated by a rule.

            Below rather than beside: those three are things to TAKE (a link, a
            PDF), these two change the registration itself, and a "Pindah
            kategori" sitting flush against "Kwitansi" reads as a fourth
            download. Ghost weight for the reason each panel already gives —
            somebody who came to check their BIB should not meet them at the
            weight of "Bayar sekarang". The gates are the server's own answers,
            never rebuilt here. */}
        {(entry.can_request_refund || entry.can_transfer_category) && (
          <div className="mt-4 flex flex-wrap items-center gap-1 border-t border-border pt-3">
            {entry.can_transfer_category && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="text-muted-foreground"
                onClick={() => setTransferOpen(true)}
              >
                <ArrowLeftRight className="h-3.5 w-3.5" />
                Pindah kategori
              </Button>
            )}
            {entry.can_request_refund && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="text-muted-foreground"
                onClick={() => setRefundOpen(true)}
              >
                <Undo2 className="h-3.5 w-3.5" />
                {entry.refund_requested ? "Ajukan refund lagi" : "Ajukan refund"}
              </Button>
            )}
          </div>
        )}

        {/* The moves that happened, and their own documents.

            This block is the ONLY place a settled top-up's invoice and receipt
            are reachable from — the entry's own receipt above deliberately does
            not mention them, because it was issued before the move and must
            keep saying what it said. Without this they exist at a URL nobody
            can discover.

            Rendered only when there is something to list: an entry that never
            moved is not owed a "Riwayat" heading over nothing. */}
        {!!entry.transfer_history?.length && (
          <div className="mt-5 border-t border-border pt-4">
            <h3 className="mb-2 text-sm font-semibold" style={{ fontFamily: "var(--font-display)" }}>
              Riwayat pindah kategori
            </h3>
            <ul className="space-y-3">
              {entry.transfer_history.map((move) => (
                <li key={move.id} className="text-sm">
                  <div className="flex items-baseline justify-between gap-3">
                    <span>Pindah ke {move.to_category ?? "—"}</span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">
                      {move.direction === "up" && `+ ${rupiah(move.amount)}`}
                      {move.direction === "down" && `− ${rupiah(move.amount)}`}
                      {move.direction === "even" && "Tanpa biaya"}
                    </span>
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">{fmtDate(move.paid_at)}</span>
                    {/* Gated on the numbers, not on the direction: an even or
                        downward move issued none, and a button derived from
                        "up" would still 404 for a row settled before the
                        numbering existed. */}
                    <ParticipantDocumentButtons
                      subject={{ kind: "race-transfer", id: move.id }}
                      hasInvoice={!!move.invoice_number}
                      hasReceipt={!!move.receipt_number}
                    />
                  </div>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Card>

      {/* The QR, and the three states it has. `entry.qr_code` is absent rather
          than null until the entry is paid — that is what lets the placeholder
          below say "aktif setelah lunas" instead of rendering an empty frame.
          A code that exists but has already been scanned still renders: the
          runner is inside, and a blank card would read as a problem. */}
      {!dead && (
        <Card className="mb-6 p-5 text-center">
          <h2 className="mb-1 text-base font-semibold" style={{ fontFamily: "var(--font-display)" }}>
            QR Check-in
          </h2>
          <p className="mb-4 text-sm text-muted-foreground">
            {entry.checked_in_at
              ? "Kamu sudah check-in."
              : "Tunjukkan QR ini di meja registrasi saat pengambilan race pack."}
          </p>

          <div className="flex justify-center">
            {entry.qr_code ? (
              <QrCode value={entry.qr_code} size={200} />
            ) : (
              <div className="grid h-[200px] w-[200px] place-items-center rounded-lg bg-[var(--bg-soft)] text-center text-sm text-muted-foreground">
                <span>
                  <Flag className="mx-auto mb-2 h-7 w-7" />
                  QR aktif setelah
                  <br />
                  pembayaran lunas
                </span>
              </div>
            )}
          </div>

          {entry.qr_code && (
            <p className="mt-3 break-all text-xs text-muted-foreground">{entry.qr_code}</p>
          )}

          {entry.bib_number && (
            <p className="mt-3 text-sm">
              Nomor BIB: <strong>{entry.bib_number}</strong>
            </p>
          )}

          {/* Gated on `bib_available`, the server's own answer, never
              reassembled from `status`/`bib_number`: a runner can be paid and
              numbered while the organizer has not printed yet, and a button
              derived from those two would 404. Absent reads as "not yet",
              which the proof page above already explains. */}
          {entry.bib_available && (
            <div className="mt-4">
              <Button
                variant="outline"
                size="sm"
                onClick={() => downloadBibMutation.mutate()}
                disabled={downloadBibMutation.isPending}
              >
                {downloadBibMutation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Download className="h-4 w-4" />
                )}
                Unduh e-BIB
              </Button>
              <p className="mt-2 text-xs text-muted-foreground">
                Cetak di kertas A5 dan pasang di depan. QR di dalamnya sama dengan QR di atas —
                satu di antaranya cukup untuk check-in.
              </p>
            </div>
          )}

          {entry.checked_in_at && (
            <Badge variant="success" className="mt-3">
              Check-in {new Date(entry.checked_in_at).toLocaleString("id-ID", {
                day: "numeric",
                month: "short",
                hour: "2-digit",
                minute: "2-digit",
              })}
            </Badge>
          )}
        </Card>
      )}

      {/* The page's footer: the receipt note and the way back to the rest of
          this account's registrations, in one centred block rather than two.
          Deliberately NOT a Card — it is the fifth on the page, and a
          display-font heading in one made plain navigation read as a section
          of content ranking with "QR Check-in".

          The link is rendered only once `sessionReady`, because the two
          branches say opposite things: before the refresh cookie has been
          exchanged a signed-in runner would be told to sign in, on the page
          their own account owns. A guest gets `?next=/participant` rather than
          a link straight there — AuthGate would bounce them to /login anyway
          and land them at its own home instead of back here. */}
      {(paid || sessionReady) && (
        <div className="mt-8 border-t border-border pt-5 text-center text-sm text-muted-foreground">
          {paid && (
            <p>
              Simpan halaman ini atau cetak kwitansinya. Kamu juga menerima
              salinannya lewat email.
            </p>
          )}
          {sessionReady && (
            <p className={paid ? "mt-2" : undefined}>
              {isAuthenticated ? (
                <>
                  Semua lomba dan tim yang kamu daftarkan ada di{" "}
                  <Link
                    href="/participant"
                    className="font-medium text-[var(--brand-600)] hover:underline"
                  >
                    Pendaftaran Saya
                  </Link>
                  .
                </>
              ) : (
                <>
                  <Link
                    href={`/login?next=${PARTICIPANT_NEXT}`}
                    className="font-medium text-[var(--brand-600)] hover:underline"
                  >
                    Masuk
                  </Link>{" "}
                  untuk melihat semua lomba dan tim yang kamu daftarkan di satu
                  halaman.
                </>
              )}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
