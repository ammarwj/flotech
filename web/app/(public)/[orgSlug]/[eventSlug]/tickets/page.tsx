"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { ChevronLeft, Ticket, Check, Minus, Plus, Loader2 } from "lucide-react";

import { getPublicTicketCategories, purchaseTickets } from "@/lib/api/tickets";
import { getPublicEvent } from "@/lib/api/events";
import { getPaymentChannels } from "@/lib/api/payments";
import { parseApiError } from "@/lib/api/errors";
import { phoneInput } from "@/lib/phone";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card } from "@/components/ui/card";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { rupiah } from "@/lib/labels";
import {
  coveredDates,
  dateRangeLabel,
  dayModeLabel,
  isSoldOut,
  priceUnitLabel,
  pricedDays,
  selectableDates,
  sellingDates,
  unitBreakdownLabel,
  usesDays,
} from "@/lib/tickets";
import { DayPickerChips } from "@/components/event/day-picker-chips";
import { cn } from "@/lib/utils";
import { useEventBase } from "@/lib/event-base";
import "../../../event-shell.css";

export default function BuyTicketsPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const router = useRouter();
  // Di custom domain halaman ini adalah `/tickets` dan eventnya adalah root.
  const { base, onCustomDomain, platformUrl, homeUrl } = useEventBase();

  const [selected, setSelected] = useState<string | null>(null);
  const [quantity, setQuantity] = useState(1);
  // Days the buyer attends. Reset whenever the category changes — a date one
  // category sells is not necessarily a date the next one does.
  const [dates, setDates] = useState<string[]>([]);
  const [buyer, setBuyer] = useState({ name: "", email: "", phone: "" });
  const [channel, setChannel] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const eventQuery = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
    retry: false,
  });

  const catsQuery = useQuery({
    queryKey: ["public-tickets", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicTicketCategories(params.orgSlug, params.eventSlug),
    retry: false,
  });

  const categories = catsQuery.data ?? [];
  // isSoldOut(), not `remaining > 0`: a day-selling category's own counter
  // answers the category's question, and a category whose every date is full is
  // sold out however many paid units its quota still allows.
  const onSale = categories.filter((c) => c.is_on_sale && !isSoldOut(c));
  const selectedCat = categories.find((c) => c.id === selected) ?? null;

  // The days this order will cover: what the buyer ticked for a per-day
  // category, every date on sale for a pass, none at all otherwise.
  const orderDates = selectedCat ? coveredDates(selectedCat, dates) : [];
  const needsDates = selectedCat?.day_mode === "per_day";

  // Seats are capped by the tightest day the buyer picked, not by the
  // category's paid-unit count — the latter would offer seats no single day has.
  const seatCeiling = (() => {
    if (!selectedCat) return 20;
    if (!usesDays(selectedCat)) return selectedCat.remaining ?? 20;

    const limits = (selectedCat.days ?? [])
      .filter((day) => orderDates.length === 0 || orderDates.includes(day.event_date))
      .map((day) => day.remaining)
      .filter((left): left is number => left !== null);

    return limits.length > 0 ? Math.min(...limits) : 20;
  })();
  const maxQty = Math.max(1, Math.min(20, seatCeiling));

  // Paid units, the mirror of the server's: seats times the days that are
  // priced. This is the one number the fee preview and the bill share.
  const pricedUnits = quantity * pricedDays(selectedCat?.day_mode ?? "none", orderDates);
  const total = (selectedCat?.price ?? 0) * pricedUnits;
  const requiresChannel = Boolean(eventQuery.data?.requires_payment_channel) && total > 0;

  // Same query key ChannelPicker uses internally — quantity included, because
  // the platform's service fee is per ticket — so react-query dedupes the
  // fetch; this just reads the cache to know the fee-inclusive total once a
  // channel is picked. `total` alone is the ticket price only and omits the
  // platform's service fee, so it understated what the buyer pays.
  const channelsQuery = useQuery({
    queryKey: ["payment-channels", total, "ticket", pricedUnits],
    queryFn: () => getPaymentChannels(total, "ticket", pricedUnits),
    enabled: requiresChannel,
  });
  const selectedBreakdown = channelsQuery.data?.find((c) => c.channel === channel) ?? null;
  const payableTotal = requiresChannel ? (selectedBreakdown?.total ?? total) : total;

  const mutation = useMutation({
    mutationFn: () =>
      purchaseTickets(params.orgSlug, params.eventSlug, {
        ticket_category_id: selected!,
        quantity,
        // Only a per-day category's dates are the buyer's to choose; a pass
        // covers its own, and the server resolves that itself.
        dates: needsDates ? orderDates : undefined,
        buyer_name: buyer.name,
        buyer_email: buyer.email,
        buyer_phone: buyer.phone || undefined,
        payment_channel: requiresChannel ? channel! : undefined,
      }),
    onSuccess: (res) => {
      if (!res.mock && res.redirect_url) {
        window.location.href = res.redirect_url;
        return;
      }
      // Halaman pesanan hidup di domain utama. Di custom domain middleware
      // memang akan me-301 path ini, tapi itu terjadi setelah navigasi klien
      // sempat mencoba merender rute yang tidak ada di sini.
      const orderPath = `/tickets/${res.order.id}`;
      if (onCustomDomain) {
        window.location.href = platformUrl(orderPath);
        return;
      }
      router.push(orderPath);
    },
    onError: (err) => setError(parseApiError(err, "Gagal memproses pembelian.").message),
  });

  const canSubmit =
    selected &&
    quantity > 0 &&
    buyer.name.trim() &&
    buyer.email.trim() &&
    (!needsDates || orderDates.length > 0) &&
    (!requiresChannel || channel);

  if (eventQuery.isError) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center" }}>
        <h1 className="section-title">Event tidak ditemukan</h1>
        <Link href={homeUrl} className="btn btn-primary btn-lg" style={{ marginTop: 24 }}>
          Ke beranda
        </Link>
      </div>
    );
  }

  return (
    <div className="container" style={{ paddingBlock: 48, maxWidth: 760 }}>
      <Link
        href={base || "/"}
        className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <ChevronLeft className="h-4 w-4" />
        Kembali ke halaman event
      </Link>

      <div className="mt-4 mb-8">
        <h1 className="text-2xl font-bold sm:text-3xl" style={{ fontFamily: "var(--font-display)" }}>
          Beli Tiket
        </h1>
        {eventQuery.data && (
          <p className="mt-1.5 text-muted-foreground">{eventQuery.data.name}</p>
        )}
      </div>

      {catsQuery.isLoading ? (
        <p className="text-muted-foreground">Memuat kategori tiket…</p>
      ) : onSale.length === 0 ? (
        <Card className="p-8 text-center">
          <div className="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)]">
            <Ticket className="h-6 w-6" />
          </div>
          <h2 className="text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
            Belum ada tiket dijual
          </h2>
          <p className="mt-1.5 text-sm text-muted-foreground">
            Penyelenggara belum membuka penjualan tiket untuk event ini.
          </p>
        </Card>
      ) : (
        <div className="grid gap-6">
          {/* Category picker */}
          <div className="grid gap-3">
            {onSale.map((cat) => {
              const active = selected === cat.id;
              return (
                <button
                  key={cat.id}
                  type="button"
                  onClick={() => {
                    setSelected(cat.id);
                    setQuantity(1);
                    setDates([]);
                    setChannel(null);
                  }}
                  className={cn(
                    "w-full rounded-xl border p-4 text-left transition-colors",
                    active
                      ? "border-[var(--brand-600)] bg-[var(--tint)]"
                      : "border-border hover:border-[var(--border-strong)]"
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                          {cat.name}
                        </span>
                        {active && <Check className="h-4 w-4 text-[var(--brand-600)]" />}
                      </div>
                      {cat.description && (
                        <p className="mt-1 text-sm text-muted-foreground">{cat.description}</p>
                      )}
                      {cat.benefits.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                          {cat.benefits.map((b) => (
                            <span
                              key={b}
                              className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-[var(--text-2)]"
                            >
                              {b}
                            </span>
                          ))}
                        </div>
                      )}
                      {usesDays(cat) && (
                        <p className="mt-2 text-xs text-muted-foreground">
                          {dayModeLabel(cat.day_mode)} · {dateRangeLabel(sellingDates(cat))}
                        </p>
                      )}
                      {!usesDays(cat) && cat.remaining !== null && (
                        <p className="mt-2 text-xs text-muted-foreground">Sisa {cat.remaining} tiket</p>
                      )}
                    </div>
                    <span className="shrink-0 text-right font-bold" style={{ fontFamily: "var(--font-display)" }}>
                      {cat.price > 0 ? rupiah(cat.price) : "Gratis"}
                      {/* The unit is the whole point of a per-day category: the
                          number beside it is what one day costs, not the trip. */}
                      {cat.price > 0 && priceUnitLabel(cat.day_mode) && (
                        <span className="block text-xs font-normal text-muted-foreground">
                          {priceUnitLabel(cat.day_mode)}
                        </span>
                      )}
                      {/* And a pass says so, since its price covers a range the
                          buyer never picks — without this the two modes read as
                          the same product at different prices. */}
                      {cat.price > 0 && cat.day_mode === "pass" && (
                        <span className="block text-xs font-normal text-muted-foreground">
                          {sellingDates(cat).length} hari
                        </span>
                      )}
                    </span>
                  </div>
                </button>
              );
            })}
          </div>

          {/* Day picker, quantity + buyer */}
          {selectedCat && (
            <Card className="grid gap-4 p-5">
              {needsDates && (
                <div>
                  <Label>Tanggal kehadiran</Label>
                  <div className="mt-2">
                    <DayPickerChips
                      days={selectableDates(selectedCat, quantity).map((day) => ({
                        ...day,
                        reason: `Sisa tiket tanggal ini tidak cukup untuk ${quantity} orang.`,
                      }))}
                      selected={dates}
                      onToggle={(date) =>
                        setDates((current) =>
                          current.includes(date)
                            ? current.filter((d) => d !== date)
                            : [...current, date].sort(),
                        )
                      }
                    />
                  </div>
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    Tiap tanggal dihitung satu harga tiket. Pilih hanya hari yang kamu hadiri.
                  </p>
                </div>
              )}

              {/* A pass is read-only: its days are the category's, so a chip
                  strip here would invite a choice that changes nothing. */}
              {selectedCat.day_mode === "pass" && (
                <div className="rounded-lg border border-border bg-[var(--bg-soft)] p-3">
                  <p className="text-sm font-semibold">Tiket terusan</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    Berlaku {dateRangeLabel(sellingDates(selectedCat))} — satu harga untuk semua
                    hari, dan kamu dapat satu QR per hari.
                  </p>
                </div>
              )}

              <div className="flex items-center justify-between">
                <Label>Jumlah orang</Label>
                <div className="flex items-center gap-3">
                  <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                    disabled={quantity <= 1}
                  >
                    <Minus className="h-4 w-4" />
                  </Button>
                  <span className="w-8 text-center font-semibold">{quantity}</span>
                  <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    onClick={() => setQuantity((q) => Math.min(maxQty, q + 1))}
                    disabled={quantity >= maxQty}
                  >
                    <Plus className="h-4 w-4" />
                  </Button>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label htmlFor="b-name">Nama pembeli</Label>
                  <Input
                    id="b-name"
                    value={buyer.name}
                    onChange={(e) => setBuyer((b) => ({ ...b, name: e.target.value }))}
                    placeholder="Nama lengkap"
                  />
                </div>
                <div>
                  <Label htmlFor="b-email">Email</Label>
                  <Input
                    id="b-email"
                    type="email"
                    value={buyer.email}
                    onChange={(e) => setBuyer((b) => ({ ...b, email: e.target.value }))}
                    placeholder="email@contoh.com"
                  />
                </div>
              </div>
              <div>
                <Label htmlFor="b-phone">No. HP (opsional)</Label>
                <Input
                  id="b-phone"
                  inputMode="tel"
                  value={buyer.phone}
                  onChange={(e) => setBuyer((b) => ({ ...b, phone: phoneInput(e.target.value) }))}
                  placeholder="08xxxxxxxxxx"
                />
              </div>

              {requiresChannel && (
                <ChannelPicker
                  amount={total}
                  audience="ticket"
                  // Paid units, not seats: the service fee is charged per
                  // ticket-day, so quoting `quantity` here would under-quote
                  // exactly what the order then charges.
                  units={pricedUnits}
                  unitLabel="tiket"
                  value={channel}
                  onChange={setChannel}
                />
              )}

              {/* ChannelPicker already ends in its own "Total bayar" line once a
                  channel is picked — this row would just repeat it. Only shown
                  when there's no channel breakdown to fall back on (free ticket,
                  or a rail that skips the picker). */}
              {/* Spelled out whenever days multiply the price, above both the
                  picker and the fallback total: a buyer billed six prices for
                  two people must be able to see where the six came from. */}
              {pricedUnits > quantity && (
                <div className="flex items-center justify-between border-t border-border pt-4 text-sm">
                  <span className="text-muted-foreground">
                    {unitBreakdownLabel(selectedCat.day_mode, quantity, orderDates)}
                  </span>
                  <span className="font-semibold">
                    {rupiah(selectedCat.price)} × {pricedUnits}
                  </span>
                </div>
              )}

              {!requiresChannel && (
                <div className="flex items-center justify-between border-t border-border pt-4">
                  <span className="text-sm text-muted-foreground">Total</span>
                  <span className="text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
                    {payableTotal > 0 ? rupiah(payableTotal) : "Gratis"}
                  </span>
                </div>
              )}

              {error && <p className="text-sm text-[var(--danger)]">{error}</p>}

              <Button
                size="lg"
                disabled={!canSubmit || mutation.isPending}
                onClick={() => {
                  setError(null);
                  mutation.mutate();
                }}
              >
                {mutation.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Memproses…
                  </>
                ) : total > 0 ? (
                  "Lanjut ke pembayaran"
                ) : (
                  "Dapatkan tiket"
                )}
              </Button>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
