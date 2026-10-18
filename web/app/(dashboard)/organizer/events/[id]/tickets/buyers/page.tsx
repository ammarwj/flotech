"use client";

import { Suspense, useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { format, parseISO } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import {
  ArrowUpRight,
  ChevronDown,
  Download,
  Mail,
  Phone,
  ScanLine,
  Search,
  Ticket,
  Users,
} from "lucide-react";

import { checkInTicketOrder, getTicketOrders } from "@/lib/api/tickets";
import { parseApiError } from "@/lib/api/errors";
import { rupiah, TICKET_PAYMENT_METHOD_LABELS } from "@/lib/labels";
import { getEvent } from "@/lib/api/events";
import { isExportEnabled, isTicketingEnabled } from "@/lib/plan";
import { isRaceEvent } from "@/lib/race";
import { RaceRouteGuard } from "@/components/event/race-route-guard";
import { ExportButtons } from "@/components/event/export-buttons";
import { ParticipantDocumentButtons } from "@/components/payment/document-buttons";
import { CopyLinkButton } from "@/components/shared/copy-link-button";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useUrlState } from "@/lib/hooks/use-url-state";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { DatePicker } from "@/components/ui/date-picker";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
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

const isFilter = (v: string | null): v is Filter =>
  !!v && FILTERS.some(([key]) => key === v);

function TicketBuyersPage() {
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();

  // Ticketing is an entitlement of this event, so the event has to be loaded.
  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });
  const ticketing = isTicketingEnabled(eventQuery.data);
  // Same pair as the tickets page: a race has no spectator ticket to have a
  // buyer of, and that is the sport, not the plan.
  const isRace = isRaceEvent(eventQuery.data);
  const canExport = isExportEnabled(eventQuery.data);

  // The whole filter row lives in the query string: this page is the one an
  // organizer keeps open and reloads all day, and a refresh that silently drops
  // back to "Semua" shows a list that answers a different question than the one
  // on screen a second ago. It also makes a filtered view linkable.
  const { params: sp, setParams } = useUrlState();

  const rawFilter = sp.get("status");
  const filter: Filter = isFilter(rawFilter) ? rawFilter : "all";
  const category = sp.get("category") ?? "";
  const range = { from: sp.get("from") ?? "", to: sp.get("to") ?? "" };
  const q = sp.get("q") ?? "";
  const page = Math.max(1, Number(sp.get("page")) || 1);

  const [search, setSearch] = useState(q);
  const [expanded, setExpanded] = useState<string | null>(null);

  const setPage = (p: number) => setParams({ page: p > 1 ? String(p) : undefined });

  // Debounce the search box so we don't fire a request per keystroke — the
  // filtering is the server's now, so every character would be a round trip.
  // The equality guard is load-bearing now that `q` comes from the URL: on
  // mount the box is seeded from it, and writing that same value back would
  // drop `page` on every refresh.
  useEffect(() => {
    if (search === q) return;
    const t = setTimeout(() => {
      setParams({ q: search || undefined, page: undefined });
    }, 350);
    return () => clearTimeout(t);
  }, [search, q, setParams]);

  const ordersQuery = useQuery({
    queryKey: ["ticket-orders", orgId, eventId, { filter, category, ...range, q, page }],
    queryFn: () =>
      getTicketOrders(orgId!, eventId, {
        status: filter === "all" ? undefined : filter,
        category_id: category || undefined,
        from: range.from || undefined,
        to: range.to || undefined,
        q: q || undefined,
        page,
      }),
    enabled: !!orgId && ticketing && !isRace,
    // The list keeps the previous page on screen while the next one loads —
    // without it every filter change blanks the page back to skeletons, and the
    // filter row jumps as the summary cards unmount under it.
    placeholderData: (prev) => prev,
  });

  /**
   * Check a paid order in without scanning each QR.
   *
   * The twin of the button in the box-office dialog, and the one that matters
   * for a gateway sale: that order is not paid when it is created, so it
   * settles after the dialog has been closed and the next person is being
   * served. The day rule is the scanner's — a pass is admitted for today only.
   */
  const checkInMut = useMutation({
    mutationFn: (orderId: string) => checkInTicketOrder(orgId!, orderId),
    onSuccess: (res) => {
      toast.success(`${res.checked_in} tiket di-check-in.`);
      qc.invalidateQueries({ queryKey: ["ticket-orders", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["ticket-report", orgId, eventId] });
      // The check-in log on the tickets page: this button admits people, and it
      // is the other door into that list besides the scanner.
      qc.invalidateQueries({ queryKey: ["checkins", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal check-in.").message),
  });

  const visible = ordersQuery.data?.items ?? [];
  const meta = ordersQuery.data?.meta;
  // Only paid orders count as sales, and tickets are counted as issued rows
  // rather than `quantity` — both derived server-side over the whole filtered
  // set, so paging through the list cannot change the totals. See
  // TicketOrderController::summary().
  const summary = ordersQuery.data?.summary;
  const categories = ordersQuery.data?.categories ?? [];

  const filtered = !!(filter !== "all" || category || range.from || range.to || q);

  // The event's own window bounds the day pickers: a ticket cannot be for a day
  // the event does not run, so offering those dates is offering a filter that
  // can only return nothing.
  const eventStart = eventQuery.data?.start_date ?? undefined;
  const eventEnd = eventQuery.data?.end_date ?? undefined;

  const resetFilters = () => {
    setSearch("");
    setParams({
      status: undefined,
      category: undefined,
      from: undefined,
      to: undefined,
      q: undefined,
      page: undefined,
    });
  };

  /** Any filter change resets the page — page 3 of a new filter is usually empty. */
  const pick = (patch: Record<string, string | undefined>) =>
    setParams({ ...patch, page: undefined });

  // `!isRace` for the reason the tickets page gives: both are true for a fun
  // run on a plan without tickets, and the upgrade is the one that cannot help.
  if (eventQuery.data && !ticketing && !isRace) {
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
              <Link href="/organizer/plans">
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
          // Nothing to export from a list that cannot exist.
          isRace ? undefined : (
          <ExportButtons
            orgId={orgId ?? undefined}
            eventId={eventId}
            kind="ticket-buyers"
            enabled={canExport}
            size="default"
          />
          )
        }
      />

      <RaceRouteGuard event={eventQuery.data} eventId={eventId} requires="competition">

      {ordersQuery.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[92px] w-full rounded-xl" />
          ))}
        </div>
      ) : /* "Nothing sold yet" and "nothing matches" are different pages, and
            with the filtering server-side an empty response no longer tells
            them apart — only an empty response with no filter on does. */
      meta?.total === 0 && !filtered ? (
        <EmptyState
          icon={Users}
          title="Belum ada pembeli"
          description="Pembelian tiket akan muncul di sini begitu penonton menyelesaikan pembayaran."
        />
      ) : (
        <>
          <div className="mb-6 grid gap-3 sm:grid-cols-3">
            <SummaryCard label="Pesanan lunas" value={String(summary?.paid_orders ?? 0)} />
            <SummaryCard label="Tiket terjual" value={String(summary?.sold_tickets ?? 0)} />
            <SummaryCard label="Pendapatan kotor" value={rupiah(summary?.revenue ?? 0)} />
          </div>

          {/* Three fields on one baseline, then the status tabs on their own
              row. Search and the status pills are both elastic, so sharing a
              row made them eat each other's width; and a labelled input beside
              an unlabelled select can never line up, whatever the gap is. */}
          <div className="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
            <Field label="Cari pembeli" className="lg:col-span-5">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Nama, email, telepon, atau pemegang tiket…"
                  className="pl-9"
                />
              </div>
            </Field>

            <Field label="Kategori" className="lg:col-span-3">
              <Select
                value={category}
                onChange={(e) => pick({ category: e.target.value || undefined })}
              >
                <option value="">Semua kategori</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
            </Field>

            {/* One range, so one field. The attendance day, not the purchase
                date: on a multi-day event the question is "who is coming on
                Saturday". An order from a category that sells no days has no
                date at all, so it drops out while a day is picked — it makes no
                claim about any particular one. */}
            <Field as="group" label="Hari hadir" className="sm:col-span-2 lg:col-span-4">
              {/* `min-w-0` on each half: the picker's trigger is `w-full`, but
                  a flex item's min-content width wins over it, so without this
                  a long date ("14 November 2026") pushes the pair out of the
                  column instead of truncating inside it. */}
              <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                <DatePicker
                  aria-label="Hari hadir, dari"
                  className="min-w-0"
                  placeholder="Mulai"
                  clearable
                  value={range.from}
                  min={eventStart}
                  max={range.to || eventEnd}
                  onChange={(v) => pick({ from: v || undefined })}
                />
                <span aria-hidden className="text-sm text-muted-foreground">
                  &ndash;
                </span>
                <DatePicker
                  aria-label="Hari hadir, sampai"
                  className="min-w-0"
                  placeholder="Selesai"
                  clearable
                  value={range.to}
                  min={range.from || eventStart}
                  max={eventEnd}
                  onChange={(v) => pick({ to: v || undefined })}
                />
              </div>
            </Field>
          </div>

          <div className="mb-4 flex flex-wrap items-center gap-1.5">
            {FILTERS.map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => pick({ status: key === "all" ? undefined : key })}
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
            {filtered && (
              <Button variant="ghost" size="sm" className="ml-auto" onClick={resetFilters}>
                Reset filter
              </Button>
            )}
          </div>

          {visible.length === 0 ? (
            <EmptyState
              icon={Search}
              title="Tidak ada pembeli yang cocok"
              description="Coba ubah kata kunci, kategori, rentang hari, atau filter status."
            />
          ) : (
            <div className="grid gap-3">
              {visible.map((order) => (
                <BuyerCard
                  key={order.id}
                  order={order}
                  open={expanded === order.id}
                  onToggle={() => setExpanded(expanded === order.id ? null : order.id)}
                  onCheckIn={() => checkInMut.mutate(order.id)}
                  checkingIn={checkInMut.isPending && checkInMut.variables === order.id}
                />
              ))}
            </div>
          )}

          {meta && meta.last_page > 1 && (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
              <span className="text-muted-foreground">
                Halaman {meta.page} dari {meta.last_page} · {meta.total} pesanan
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage(Math.max(1, page - 1))}
                  disabled={meta.page <= 1}
                >
                  Sebelumnya
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage(page + 1)}
                  disabled={meta.page >= meta.last_page}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </>
      )}
      </RaceRouteGuard>
    </div>
  );
}

export default function Page() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <TicketBuyersPage />
    </Suspense>
  );
}

/**
 * One labelled control in the filter row.
 *
 * Every field carries a label, including the ones whose placeholder already
 * says what they are: a labelled input and a bare select sitting in the same
 * grid start at different heights, so the row can never settle on one baseline.
 *
 * `as="group"` is for a field holding more than one control — the day range.
 * A <label> wrapping two of them points at whichever comes first, so clicking
 * "Hari hadir" would open the *from* picker while the pointer sits over *to*.
 * Those halves carry their own aria-labels instead.
 */
function Field({
  as = "label",
  label,
  className,
  children,
}: {
  as?: "label" | "group";
  label: string;
  className?: string;
  children: ReactNode;
}) {
  const Tag = as === "group" ? "div" : "label";

  return (
    <Tag
      className={cn("grid gap-1.5", className)}
      role={as === "group" ? "group" : undefined}
      aria-label={as === "group" ? label : undefined}
    >
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      {children}
    </Tag>
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
  onCheckIn,
  checkingIn,
}: {
  order: TicketOrder;
  open: boolean;
  onToggle: () => void;
  onCheckIn: () => void;
  checkingIn?: boolean;
}) {
  const tickets = order.tickets ?? [];
  const checkedIn = tickets.filter((t) => t.is_used).length;
  // Offered only while there is somebody left to admit. An unpaid order has
  // nobody yet, and a fully scanned one is already in — the server refuses both
  // anyway, but a button that can only fail is worse than no button.
  const canCheckIn = order.status === "paid" && checkedIn < tickets.length;

  return (
    <Card className="overflow-hidden">
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full items-start gap-3 p-4 text-left transition-colors hover:bg-muted/40"
      >
        {/* Stacked on a phone, one row from `sm` up. The two blocks cannot sit
            side by side at that width: an email address is a single unbreakable
            token, so it overflows its column and runs under the price instead
            of wrapping. */}
        <div className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="min-w-0 truncate font-semibold">{order.buyer_name}</p>
              <TicketOrderStatusBadge status={order.status} />
              {order.category && <Badge variant="neutral">{order.category.name}</Badge>}
              {/* Which rail took the money. Only for the two off-platform ones:
                  a gateway sale is the default and labelling every row with it
                  would be noise. Cash matters here because that revenue is real
                  but never reaches the wallet, and the buyer list is where an
                  organizer reconciles the two. */}
              {order.payment_method !== "gateway" && (
                <Badge variant="outline">
                  {TICKET_PAYMENT_METHOD_LABELS[order.payment_method]}
                </Badge>
              )}
            </div>
            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
              <span className="inline-flex min-w-0 max-w-full items-center gap-1">
                <Mail className="h-3.5 w-3.5 flex-shrink-0" />
                {/* Truncated, not wrapped: an address has no break opportunity,
                    so left to itself it widens the row past the viewport. */}
                <span className="truncate">{order.buyer_email}</span>
              </span>
              {order.buyer_phone && (
                <span className="inline-flex items-center gap-1">
                  <Phone className="h-3.5 w-3.5 flex-shrink-0" />
                  {order.buyer_phone}
                </span>
              )}
              <span>{fmtDateTime(order.created_at)}</span>
            </div>
          </div>

          {/* Left-aligned under the details on a phone, right-aligned beside
              them from `sm` up — a right edge the stacked block no longer
              shares with anything reads as a stray column. */}
          <div className="flex-shrink-0 text-left sm:text-right">
            <p className="font-semibold">{rupiah(order.total_price)}</p>
            <p className="text-xs text-muted-foreground">
              {tickets.length} tiket · check-in {checkedIn}/{tickets.length}
            </p>
          </div>
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

          {/* The same documents the buyer gets. The organizer sold the ticket
              and is named as the issuer on them, so they are theirs to read —
              and this is the surface where a buyer's "can you resend my
              receipt?" gets answered. */}
          <div className="mt-3 border-t border-border pt-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              E-tiket &amp; dokumen
            </p>
            <div className="flex flex-wrap gap-2">
              {/* The buyer's own page, which needs no login — the order id is
                  the credential. This is the link to hand back to someone who
                  lost the email. */}
              {/* Admit the holder standing right here, without routing them
                  through the scanner to read a code off a screen they are
                  already in front of. */}
              {canCheckIn && (
                <Button size="sm" onClick={onCheckIn} disabled={checkingIn}>
                  <ScanLine className="h-4 w-4" />
                  Check-in
                </Button>
              )}
              <CopyLinkButton path={`/tickets/${order.id}`} label="Salin tautan e-tiket" />
              <ParticipantDocumentButtons
                subject={{ kind: "ticket-order", id: order.id }}
                hasInvoice={!!order.invoice_number}
                hasReceipt={!!order.receipt_number}
              />
            </div>
          </div>
        </div>
      )}
    </Card>
  );
}
