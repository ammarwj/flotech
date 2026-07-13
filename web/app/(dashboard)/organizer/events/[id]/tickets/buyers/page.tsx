"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { format, parseISO } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import {
  ArrowUpRight,
  ChevronDown,
  Download,
  Mail,
  Phone,
  Search,
  Ticket,
  Users,
} from "lucide-react";

import { getTicketOrders } from "@/lib/api/tickets";
import { downloadCsv, slugifyFileName, toCsv } from "@/lib/csv";
import { rupiah, TICKET_ORDER_STATUS_LABELS } from "@/lib/labels";
import { isTicketingEnabled } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { TicketOrderStatusBadge } from "@/components/shared/status-badge";
import type { TicketOrder, TicketOrderStatus } from "@/types/api";

type Filter = "all" | TicketOrderStatus;

const FILTERS: [Filter, string][] = [
  ["all", "Semua"],
  ["paid", "Lunas"],
  ["pending", "Menunggu Pembayaran"],
  ["cancelled", "Dibatalkan"],
  ["refunded", "Dikembalikan"],
];

const fmtDateTime = (iso: string | null | undefined) =>
  iso ? format(parseISO(iso), "d MMM yyyy, HH:mm", { locale: idLocale }) : "—";

export default function TicketBuyersPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { org, orgId } = useActiveOrg();
  const ticketing = isTicketingEnabled(org);

  const [filter, setFilter] = useState<Filter>("all");
  const [search, setSearch] = useState("");
  const [expanded, setExpanded] = useState<string | null>(null);

  const ordersQuery = useQuery({
    queryKey: ["ticket-orders", orgId, eventId],
    queryFn: () => getTicketOrders(orgId!, eventId),
    enabled: !!orgId && ticketing,
  });

  const orders = useMemo(() => ordersQuery.data ?? [], [ordersQuery.data]);

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();

    return orders.filter((o) => {
      if (filter !== "all" && o.status !== filter) return false;
      if (!q) return true;
      return (
        o.buyer_name.toLowerCase().includes(q) ||
        o.buyer_email.toLowerCase().includes(q) ||
        (o.buyer_phone ?? "").toLowerCase().includes(q) ||
        (o.tickets ?? []).some((t) => (t.holder_name ?? "").toLowerCase().includes(q))
      );
    });
  }, [orders, filter, search]);

  // Only paid orders count as sales — pending ones still hold quota, but no money.
  const paid = orders.filter((o) => o.status === "paid");
  const soldTickets = paid.reduce((s, o) => s + o.quantity, 0);
  const revenue = paid.reduce((s, o) => s + o.total_price, 0);

  const exportCsv = () => {
    const csv = toCsv(
      [
        "Tanggal",
        "Nama Pembeli",
        "Email",
        "Telepon",
        "Kategori",
        "Jumlah",
        "Total",
        "Status",
        "Dibayar",
        "Check-in",
        "Nama di Tiket",
      ],
      visible.map((o) => [
        fmtDateTime(o.created_at),
        o.buyer_name,
        o.buyer_email,
        o.buyer_phone ?? "",
        o.category?.name ?? "",
        o.quantity,
        o.total_price,
        TICKET_ORDER_STATUS_LABELS[o.status],
        fmtDateTime(o.paid_at),
        `${(o.tickets ?? []).filter((t) => t.is_used).length}/${o.quantity}`,
        (o.tickets ?? []).map((t) => t.holder_name ?? "—").join(", "),
      ])
    );

    downloadCsv(`pembeli-tiket-${slugifyFileName(org?.name ?? "event")}`, csv);
  };

  if (org && !ticketing) {
    return (
      <div>
        <PageHeader
          title="Pembeli Tiket"
          description="Daftar penonton yang membeli tiket event ini."
          backHref={`/organizer/events/${eventId}/tickets`}
          backLabel="Tiket"
        />
        <EmptyState
          icon={Ticket}
          title="Fitur tiket belum aktif di paketmu"
          description="Upgrade paket untuk menjual tiket QR Code dan melihat daftar pembelinya."
          action={
            <Button asChild>
              <Link href="/organizer/upgrade">
                <ArrowUpRight className="h-4 w-4" />
                Upgrade paket
              </Link>
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Pembeli Tiket"
        description="Setiap pembelian tiket beserta status pembayaran dan check-in-nya."
        backHref={`/organizer/events/${eventId}/tickets`}
        backLabel="Tiket"
        actions={
          <Button variant="outline" onClick={exportCsv} disabled={visible.length === 0}>
            <Download className="h-4 w-4" />
            Export CSV
          </Button>
        }
      />

      {ordersQuery.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[92px] w-full rounded-xl" />
          ))}
        </div>
      ) : orders.length === 0 ? (
        <EmptyState
          icon={Users}
          title="Belum ada pembeli"
          description="Pembelian tiket akan muncul di sini begitu penonton menyelesaikan pembayaran."
        />
      ) : (
        <>
          <div className="mb-6 grid gap-3 sm:grid-cols-3">
            <SummaryCard label="Pesanan lunas" value={String(paid.length)} />
            <SummaryCard label="Tiket terjual" value={String(soldTickets)} />
            <SummaryCard label="Pendapatan kotor" value={rupiah(revenue)} />
          </div>

          <div className="mb-4 flex flex-wrap items-center gap-3">
            <div className="relative min-w-[220px] flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari nama, email, atau telepon…"
                className="pl-9"
              />
            </div>
            <div className="flex flex-wrap gap-1.5">
              {FILTERS.map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setFilter(key)}
                  className={cn(
                    "rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors",
                    filter === key
                      ? "border-transparent bg-foreground text-background"
                      : "border-border text-muted-foreground hover:text-foreground"
                  )}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          {visible.length === 0 ? (
            <EmptyState
              icon={Search}
              title="Tidak ada pembeli yang cocok"
              description="Coba ubah kata kunci atau filter status."
            />
          ) : (
            <div className="grid gap-3">
              {visible.map((order) => (
                <BuyerCard
                  key={order.id}
                  order={order}
                  open={expanded === order.id}
                  onToggle={() => setExpanded(expanded === order.id ? null : order.id)}
                />
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        {value}
      </p>
    </Card>
  );
}

function BuyerCard({
  order,
  open,
  onToggle,
}: {
  order: TicketOrder;
  open: boolean;
  onToggle: () => void;
}) {
  const tickets = order.tickets ?? [];
  const checkedIn = tickets.filter((t) => t.is_used).length;

  return (
    <Card className="overflow-hidden">
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full items-center gap-4 p-4 text-left transition-colors hover:bg-muted/40"
      >
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="truncate font-semibold">{order.buyer_name}</p>
            <TicketOrderStatusBadge status={order.status} />
            {order.category && <Badge variant="neutral">{order.category.name}</Badge>}
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <Mail className="h-3.5 w-3.5" />
              {order.buyer_email}
            </span>
            {order.buyer_phone && (
              <span className="inline-flex items-center gap-1">
                <Phone className="h-3.5 w-3.5" />
                {order.buyer_phone}
              </span>
            )}
            <span>{fmtDateTime(order.created_at)}</span>
          </div>
        </div>

        <div className="flex-shrink-0 text-right">
          <p className="font-semibold">{rupiah(order.total_price)}</p>
          <p className="text-xs text-muted-foreground">
            {order.quantity} tiket · check-in {checkedIn}/{order.quantity}
          </p>
        </div>

        <ChevronDown
          className={cn(
            "h-4 w-4 flex-shrink-0 text-muted-foreground transition-transform",
            open && "rotate-180"
          )}
        />
      </button>

      {open && (
        <div className="border-t border-border bg-muted/20 px-4 py-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Tiket ({tickets.length})
          </p>
          {tickets.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Tiket sudah dilepas karena pesanan ini dibatalkan atau direfund.
            </p>
          ) : (
            <ul className="grid gap-2">
              {tickets.map((t) => (
                <li key={t.id} className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="font-medium">{t.holder_name ?? order.buyer_name}</span>
                  <code className="text-[11px] text-muted-foreground">{t.qr_code}</code>
                  {t.is_used ? (
                    <Badge variant="success">Check-in {fmtDateTime(t.used_at)}</Badge>
                  ) : (
                    <Badge variant="neutral">Belum check-in</Badge>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  );
}
