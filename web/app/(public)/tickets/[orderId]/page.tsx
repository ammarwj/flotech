"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Clock, CalendarDays, MapPin, Ticket as TicketIcon } from "lucide-react";

import { getTicketOrder } from "@/lib/api/tickets";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { QrCode } from "@/components/event/qr-code";
import { rupiah, TICKET_ORDER_STATUS_LABELS } from "@/lib/labels";
import "../../event-shell.css";

function fmtDate(d: string | null) {
  if (!d) return "Tanggal menyusul";
  return new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" });
}

export default function ETicketPage() {
  const params = useParams<{ orderId: string }>();

  const query = useQuery({
    queryKey: ["ticket-order", params.orderId],
    queryFn: () => getTicketOrder(params.orderId),
    retry: false,
    // Poll while awaiting payment so the e-tickets unlock once settled.
    refetchInterval: (q) => (q.state.data?.status === "pending" ? 4000 : false),
  });

  if (query.isLoading) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center", color: "var(--text-muted)" }}>
        Memuat tiket…
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center" }}>
        <h1 className="section-title">Pesanan tidak ditemukan</h1>
        <p className="section-sub">Periksa kembali tautan e-tiketmu.</p>
        <Link href="/" className="btn btn-primary btn-lg" style={{ marginTop: 24 }}>
          Ke beranda
        </Link>
      </div>
    );
  }

  const order = query.data;
  const paid = order.status === "paid";

  return (
    <div className="container" style={{ paddingBlock: 48, maxWidth: 640 }}>
      {/* Status banner */}
      <Card
        className="mb-6 flex items-center gap-3 p-5"
        style={{
          borderColor: paid ? "var(--success)" : "var(--warning)",
          background: `color-mix(in srgb, ${paid ? "var(--success)" : "var(--warning)"} 8%, transparent)`,
        }}
      >
        {paid ? (
          <CheckCircle2 className="h-8 w-8 shrink-0 text-[var(--success)]" />
        ) : (
          <Clock className="h-8 w-8 shrink-0 text-[var(--warning)]" />
        )}
        <div>
          <h1 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {paid ? "Pembayaran berhasil 🎉" : "Menunggu pembayaran"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {paid
              ? "Tunjukkan QR Code di bawah ke petugas saat check-in."
              : "Selesaikan pembayaran agar tiket aktif. Halaman ini akan diperbarui otomatis."}
          </p>
        </div>
      </Card>

      {/* Event + order summary */}
      <Card className="mb-6 p-5">
        <h2 className="text-base font-semibold" style={{ fontFamily: "var(--font-display)" }}>
          {order.event?.name}
        </h2>
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <CalendarDays className="h-4 w-4" />
            {fmtDate(order.event?.start_date ?? null)}
          </span>
          {order.event?.location_name && (
            <span className="inline-flex items-center gap-1.5">
              <MapPin className="h-4 w-4" />
              {order.event.location_name}
            </span>
          )}
        </div>
        <div className="mt-4 grid gap-1.5 border-t border-border pt-4 text-sm">
          <Row label="Kategori" value={order.category?.name ?? "-"} />
          <Row label="Jumlah" value={`${order.quantity} tiket`} />
          <Row label="Total" value={order.total_price > 0 ? rupiah(order.total_price) : "Gratis"} />
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">Status</span>
            <Badge variant={paid ? "success" : "warning"}>
              {TICKET_ORDER_STATUS_LABELS[order.status]}
            </Badge>
          </div>
        </div>
      </Card>

      {/* E-tickets */}
      <h2 className="mb-3 text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
        E-Tiket ({order.tickets?.length ?? 0})
      </h2>
      <div className="grid gap-4">
        {order.tickets?.map((t, i) => (
          <Card key={t.id} className="flex flex-col items-center gap-3 p-6 text-center">
            {paid ? (
              <QrCode value={t.qr_code} size={200} />
            ) : (
              <div className="grid h-[200px] w-[200px] place-items-center rounded-lg bg-[var(--bg-soft)] text-center text-sm text-muted-foreground">
                <span>
                  <TicketIcon className="mx-auto mb-2 h-7 w-7" />
                  QR aktif setelah
                  <br />
                  pembayaran lunas
                </span>
              </div>
            )}
            <div>
              <div className="font-semibold">{t.holder_name ?? `Tiket #${i + 1}`}</div>
              <div className="text-xs text-muted-foreground">{order.category?.name}</div>
              {paid && t.is_used && (
                <Badge variant="danger" className="mt-2">
                  Sudah digunakan
                </Badge>
              )}
            </div>
            {paid && <code className="text-[11px] text-muted-foreground">{t.qr_code}</code>}
          </Card>
        ))}
      </div>

      <p className="mt-6 text-center text-xs text-muted-foreground">
        Simpan tautan halaman ini untuk mengakses tiketmu kapan saja.
      </p>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
