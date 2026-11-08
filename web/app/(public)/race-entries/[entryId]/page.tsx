"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CheckCircle2, Clock, Download, Flag, Loader2 } from "lucide-react";

import { getRaceEntry, requestRaceEntryRefund, submitRaceEntryProof } from "@/lib/api/race";
import { downloadMyBib } from "@/lib/api/bibs";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import { Card } from "@/components/ui/card";
import { QrCode } from "@/components/event/qr-code";
import { Badge } from "@/components/ui/badge";
import { ManualTransferPanel } from "@/components/payment/manual-transfer-panel";
import { RefundRequestPanel } from "@/components/payment/refund-request-panel";
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
      />

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
