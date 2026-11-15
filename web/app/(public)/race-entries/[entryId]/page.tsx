"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  ArrowLeftRight,
  CheckCircle2,
  Clock,
  Download,
  Flag,
  Loader2,
  Undo2,
} from "lucide-react";

import {
  cancelRaceTransfer,
  getRaceEntry,
  payRaceTransfer,
  submitRaceEntryProof,
  submitRaceTransferProof,
} from "@/lib/api/race";
import { downloadMyBib } from "@/lib/api/bibs";
import { parseApiError } from "@/lib/api/errors";
import { useRaceEntryActions } from "@/lib/hooks/use-race-entry-actions";
import { Button } from "@/components/ui/button";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import { Card } from "@/components/ui/card";
import { QrCode } from "@/components/event/qr-code";
import { Badge } from "@/components/ui/badge";
import { Disclosure } from "@/components/ui/disclosure";
import { DetailRow, PriceBreakdown } from "@/components/race/entry-detail-rows";
import { ManualTransferPanel } from "@/components/payment/manual-transfer-panel";
import { RefundRequestPanel } from "@/components/payment/refund-request-panel";
import { CategoryTransferPanel } from "@/components/race/category-transfer-panel";
import { ParticipantDocumentButtons } from "@/components/payment/document-buttons";
import { CopyLinkButton } from "@/components/shared/copy-link-button";
import { rupiah } from "@/lib/labels";
import "../../event-shell.css";

/**
 * One of the four things a runner is asked for at the race-pack desk.
 *
 * Label above value rather than the label-left/value-right of the folded
 * sections, and the difference is the job: those are lines to check against a
 * receipt, these are answers to read out. Stacked, the value is what the eye
 * lands on.
 */
function Fact({
  label,
  value,
  numeric,
}: {
  label: string;
  value: string;
  numeric?: boolean;
}) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      {/* Wraps rather than truncates. `truncate` turned "22 November 2026" into
          "22 November 2…" at phone width — on the one line somebody reads out
          at the race-pack desk, which is the opposite of the job. A second line
          costs nothing here; a cut-off date costs the answer. */}
      <dd className={`mt-0.5 font-semibold ${numeric ? "tabular-nums" : ""}`}>
        {value}
      </dd>
    </div>
  );
}

function fmtDate(d: string | null | undefined) {
  if (!d) return "Tanggal menyusul";
  return new Date(d).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
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
    mutationFn: (proofUrl: string) =>
      submitRaceEntryProof(params.entryId, proofUrl),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success("Bukti terkirim", {
        description: "Menunggu verifikasi penyelenggara.",
      });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal mengirim bukti.").message),
  });

  // Refund and category move, shared with the participant dashboard which
  // offers the same two from its list — see the hook for why the invalidation
  // is the part worth not writing twice. The three doors onto an OPEN move
  // (pay, abandon, upload proof) stay here: they belong to the panel below,
  // which is the only place with room for a bank account and an upload field.
  const { refund, transfer } = useRaceEntryActions(params.entryId);

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
    mutationFn: ({
      transferId,
      channel,
    }: {
      transferId: string;
      channel: string;
    }) => payRaceTransfer(transferId, channel),
    onSuccess: (res) => {
      if (res.payment_method === "gateway" && !res.mock && res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }

      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success(
        res.transfer.status === "paid"
          ? "Kategori berhasil dipindah"
          : "Lanjutkan pembayaran",
        {
          description:
            res.payment_method === "manual"
              ? "Pembayaran online sedang tidak tersedia. Transfer manual sesuai panel di bawah."
              : undefined,
        },
      );
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal membuka pembayaran.").message),
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
      qc.invalidateQueries({
        queryKey: ["race-transfer-options", params.entryId],
      });
      toast.success("Pindah kategori dibatalkan", {
        description: "Pendaftaranmu tetap di kategori sekarang.",
      });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal membatalkan.").message),
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
    mutationFn: ({
      transferId,
      proofUrl,
    }: {
      transferId: string;
      proofUrl: string;
    }) => submitRaceTransferProof(transferId, proofUrl),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["race-entry", params.entryId] });
      toast.success("Bukti terkirim", {
        description: "Menunggu verifikasi penyelenggara.",
      });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal mengirim bukti.").message),
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
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal mengunduh e-BIB.").message),
  });

  if (query.isLoading) {
    return (
      <div
        className="container"
        style={{
          paddingBlock: 96,
          textAlign: "center",
          color: "var(--text-muted)",
        }}
      >
        Memuat pendaftaran…
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <div
        className="container"
        style={{ paddingBlock: 96, textAlign: "center" }}
      >
        <h1 className="section-title">Pendaftaran tidak ditemukan</h1>
        <p className="section-sub">
          Periksa kembali tautan bukti pendaftaranmu.
        </p>
        <Link
          href="/"
          className="btn btn-primary btn-lg"
          style={{ marginTop: 24 }}
        >
          Ke beranda
        </Link>
      </div>
    );
  }

  const entry = query.data;
  const paid = entry.status === "paid";
  const manual =
    entry.payment_method === "manual" && entry.status === "pending";
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

  // What the runner has actually handed over, once category moves are in — see
  // PriceBreakdown for why both figures are published and both are labelled.
  const paidTotal = entry.paid_total ?? entry.gross_amount;

  return (
    <div className="container" style={{ paddingBlock: 48, maxWidth: 640 }}>
      {/* Status banner */}
      <Card
        className="mb-6 flex items-center gap-3 p-5"
        style={{
          borderColor: dead
            ? "var(--danger)"
            : paid
              ? "var(--success)"
              : "var(--warning)",
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
          <h1
            className="text-lg font-bold"
            style={{ fontFamily: "var(--font-display)" }}
          >
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
        onPay={(transferId, channel) =>
          payTransfer.mutate({ transferId, channel })
        }
        onCancel={(transferId) => cancelTransfer.mutate(transferId)}
        pending={
          transfer.isPending ||
          payTransfer.isPending ||
          cancelTransfer.isPending
        }
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

      {/* ---- The entry itself ----

          Rebuilt around what somebody actually opens this page for. It had
          grown into one card holding six stacked blocks — event facts, a fee
          breakdown, a status badge, three buttons, two more buttons, the form
          answers, the move history — all at one weight, so the thing a runner
          needs at the race-pack desk sat in the same grey as the PPN line.

          Now: the four facts they will be asked for, then the things they can
          do, then everything else folded away. What is folded is not hidden —
          `<details>` stays searchable in-page and keyboard-operable, which is
          why it is native and not a `useState` toggle. */}
      <Card className="mb-6 overflow-hidden">
        <div className="border-b border-border p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h2
                className="text-lg font-bold leading-tight"
                style={{ fontFamily: "var(--font-display)" }}
              >
                {entry.full_name}
              </h2>
              <p className="mt-0.5 text-sm text-muted-foreground">
                {entry.event?.name ?? "—"}
              </p>
            </div>
            <Badge variant={paid ? "success" : dead ? "danger" : "warning"}>
              {entry.awaiting_verification
                ? "Menunggu verifikasi"
                : entry.status}
            </Badge>
          </div>

          {/* The four facts somebody is asked for at the desk, given room
              instead of a row in a nine-line table. Two columns rather than
              four: on a phone four tracks collapse into unreadable slivers,
              and these pair naturally — where and when, what and how much. */}
          <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
            <Fact label="Kategori" value={entry.category?.name ?? "—"} />
            <Fact
              label="Nomor BIB"
              value={entry.bib_number ?? "Belum terbit"}
            />
            <Fact label="Tanggal" value={fmtDate(entry.event?.start_date)} />
            <Fact
              label="Total dibayar"
              value={paidTotal > 0 ? rupiah(paidTotal) : "Gratis"}
              numeric
            />
          </dl>

          {entry.event?.location_name && (
            <p className="mt-3 text-sm text-muted-foreground">
              {entry.event.location_name}
            </p>
          )}
        </div>

        {/* ---- What they can do ----

            Documents first because they are what the page is for, then the two
            that change the registration — a "Pindah kategori" flush against
            "Kwitansi" reads as a fourth download, so they keep their own row
            and their ghost weight. The gates are the server's own answers,
            never rebuilt here. */}
        <div className="flex flex-wrap items-center gap-2 border-b border-border p-5">
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
          {/* Left-aligned and wrapping naturally, with neither `ml-auto` nor a
              divider — both were tried against the real page and both read as
              accidents: five controls never fit one line here, so the pushed
              group landed as a second line hugging the right edge, and the rule
              meant to replace it became a stub dangling at the start of that
              line. What separates the two groups is already doing the work:
              outlined things to TAKE, ghost things that CHANGE the
              registration. */}
          {(entry.can_transfer_category || entry.can_request_refund) && (
            <span className="flex flex-wrap items-center gap-1">
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
                  {entry.refund_requested
                    ? "Ajukan refund lagi"
                    : "Ajukan refund"}
                </Button>
              )}
            </span>
          )}
        </div>

        {/* ---- Everything worth keeping, folded ----

            Each section carries its gist in the summary, so folding costs the
            reader nothing: the breakdown shows its total, the answers their
            count, the history how many moves. Somebody checking one number
            never has to open anything. */}
        <div className="px-5">
          <Disclosure
            title="Rincian biaya"
            summary={<span className="tabular-nums">{rupiah(paidTotal)}</span>}
          >
            {/* The period sits with the money, not with the event facts: it is
                what makes the price checkable — the same category costs three
                different amounts over a sale — and it means nothing beside a
                date. */}
            <DetailRow label="Periode" value={entry.period_name} />
            <PriceBreakdown
              price={entry.price}
              serviceFee={entry.service_fee}
              gatewayFee={entry.gateway_fee}
              gatewayTax={entry.gateway_tax}
              grossAmount={entry.gross_amount}
              paidTotal={paidTotal}
            />
          </Disclosure>

          {/* The moves that happened, and their own documents.

              Directly under the breakdown, because it is the rest of the same
              sentence: "Total awal Rp 125.440 / Total dibayar Rp 142.880" is a
              gap of Rp 11.000 + fees, and this is the only block that accounts
              for it. Separating them with the form answers put fourteen lines
              about jersey size between a figure and its explanation.

              This block is also the ONLY place a settled top-up's invoice and
              receipt are reachable from — the entry's own receipt deliberately
              does not mention them, because it was issued before the move and
              must keep saying what it said. Without this they exist at a URL
              nobody can discover. */}
          {!!entry.transfer_history?.length && (
            <Disclosure
              title="Riwayat pindah kategori"
              summary={`${entry.transfer_history.length}×`}
            >
              <ul className="space-y-3">
                {entry.transfer_history.map((move) => (
                  <li key={move.id} className="text-sm">
                    {/* The date joins the title line rather than sitting beside
                        the buttons below it: on one row with them it reads as
                        their caption, which is the one thing it is not. */}
                    <div className="flex items-baseline justify-between gap-4">
                      {/* The date on its own line, not trailing the title:
                          inline it wrapped mid-phrase at phone width ("15" on
                          one line, "November 2026" on the next) between a
                          heading and an amount. */}
                      <span className="min-w-0">
                        <span className="block">
                          Pindah ke {move.to_category ?? "—"}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                          {fmtDate(move.paid_at)}
                        </span>
                      </span>
                      <span className="shrink-0 tabular-nums text-muted-foreground">
                        {move.direction === "up" && `+ ${rupiah(move.amount)}`}
                        {move.direction === "down" &&
                          `− ${rupiah(move.amount)}`}
                        {move.direction === "even" && "Tanpa biaya"}
                      </span>
                    </div>
                    {/* Gated on the numbers, not on the direction: an even or
                        downward move issued none, and a button derived from
                        "up" would still 404 for a row settled before the
                        numbering existed. */}
                    <div className="mt-2">
                      <ParticipantDocumentButtons
                        subject={{ kind: "race-transfer", id: move.id }}
                        hasInvoice={!!move.invoice_number}
                        hasReceipt={!!move.receipt_number}
                      />
                    </div>
                  </li>
                ))}
              </ul>
            </Disclosure>
          )}

          {/* What they actually filled in — last, because it is the one section
              that is not about money. The two above answer "am I paid up and
              where did that figure come from"; this answers "did I type my
              details right", which is a question somebody asks on their own
              time rather than at a payment deadline.

              Read off `answers`, which the server resolves against the event's
              own form schema — never `custom_fields`, whose keys
              (`status_mahasiswa_uad_umum`) are the one shape a runner checking
              their own submission cannot use. Here rather than on a dashboard
              twin for the reason the documents are: a runner may have arrived
              from an email and never signed up.

              Absent on a surface that did not load the schema, and empty on an
              event that asks nothing beyond the built-in fields — both render
              nothing rather than an empty heading. */}
          {!!entry.answers?.length && (
            <Disclosure
              title="Data pendaftaran"
              summary={`${entry.answers.length} isian`}
            >
              {/* No `space-y` here: DetailRow carries its own vertical rhythm,
                  and stacking the two gaps makes a fourteen-line list twice as
                  tall as it reads. */}
              <dl>
                {entry.answers.map((answer) => (
                  <DetailRow
                    key={answer.key}
                    label={answer.label}
                    value={answer.value}
                  />
                ))}
              </dl>
              <p className="mt-3 text-xs text-muted-foreground">
                Ada yang salah? Hubungi penyelenggara.
              </p>
            </Disclosure>
          )}
        </div>
      </Card>

      {/* The QR, and the three states it has. `entry.qr_code` is absent rather
          than null until the entry is paid — that is what lets the placeholder
          below say "aktif setelah lunas" instead of rendering an empty frame.
          A code that exists but has already been scanned still renders: the
          runner is inside, and a blank card would read as a problem. */}
      {!dead && (
        <Card className="mb-6 p-5 text-center">
          <h2
            className="mb-1 text-base font-semibold"
            style={{ fontFamily: "var(--font-display)" }}
          >
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

          {/* The raw code, for a desk whose scanner is down and has to type it
              in. Small and muted because that is the fallback, not the
              instrument — and `break-all` because a ULID has no spaces to wrap
              at. The BIB number that used to sit under it is gone: it is one of
              the four facts at the top of the page now, and printing it twice
              on one screen invites somebody to check whether they match. */}
          {entry.qr_code && (
            <p className="mt-3 break-all text-xs text-muted-foreground">
              {entry.qr_code}
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
                Cetak di kertas A5 dan pasang di depan. QR di dalamnya sama
                dengan QR di atas — satu di antaranya cukup untuk check-in.
              </p>
            </div>
          )}

          {entry.checked_in_at && (
            <Badge variant="success" className="mt-3">
              Check-in{" "}
              {new Date(entry.checked_in_at).toLocaleString("id-ID", {
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
