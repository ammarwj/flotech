"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CheckCircle2, Clock, Flag } from "lucide-react";

import { getRaceEntry, submitRaceEntryProof } from "@/lib/api/race";
import { parseApiError } from "@/lib/api/errors";
import { Card } from "@/components/ui/card";
import { QrCode } from "@/components/event/qr-code";
import { Badge } from "@/components/ui/badge";
import { ManualTransferPanel } from "@/components/payment/manual-transfer-panel";
import { ParticipantDocumentButtons } from "@/components/payment/document-buttons";
import { CopyLinkButton } from "@/components/shared/copy-link-button";
import { rupiah } from "@/lib/labels";
import "../../event-shell.css";

function fmtDate(d: string | null | undefined) {
  if (!d) return "Tanggal menyusul";
  return new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" });
}

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
  const dead = entry.status === "cancelled" || entry.status === "expired";

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

      {paid && (
        <p className="text-center text-sm text-muted-foreground">
          Simpan halaman ini atau cetak kwitansinya. Kamu juga menerima salinannya
          lewat email.
        </p>
      )}
    </div>
  );
}
