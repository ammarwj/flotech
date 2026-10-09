"use client";

import { type ComponentProps, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Banknote,
  CheckCircle2,
  Loader2,
  Minus,
  Plus,
  QrCode as QrCodeIcon,
  Store,
} from "lucide-react";

import {
  getTicketOrder,
  type TicketSalePayload,
  type TicketSaleResult,
} from "@/lib/api/tickets";
import type { SportEvent, TicketCategory } from "@/types/api";
import type { FieldErrors } from "@/lib/api/errors";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { DayPickerChips } from "@/components/event/day-picker-chips";
import { QrCode } from "@/components/event/qr-code";
import { CopyLinkButton } from "@/components/shared/copy-link-button";
import { rupiah } from "@/lib/labels";
import { nameInput } from "@/lib/name";
import { phoneInput } from "@/lib/phone";
import {
  coveredDates,
  dateRangeLabel,
  isSoldOut,
  priceUnitLabel,
  pricedDays,
  seatCeiling,
  selectableDates,
  sellingDates,
  unitBreakdownLabel,
  unitCountLabel,
} from "@/lib/tickets";

type Rail = "gateway" | "onsite";

/**
 * The box office: the organizer selling a ticket at the venue.
 *
 * The same questions the public shop asks, staffed — so every per-day rule comes
 * from `lib/tickets.ts` rather than being re-derived here, exactly as it does on
 * the buyer's page. Two differences, both deliberate:
 *
 * - categories are a `<select>`, not the buyer's card picker: a till needs
 *   speed, and the staff already knows what they are selling;
 * - a category whose sale window has closed is still offered, because the venue
 *   sells after the online window does. Quota is not relaxed — only the clock.
 *
 * Two panes in one dialog. The form, then what to do with the order it made:
 * a QR the buyer scans with their own phone for a gateway sale, or a paid badge
 * and a check-in button for cash. The staff's screen never leaves the dashboard
 * either way, which is the point — there is a queue behind this person.
 *
 * Which of those two it will be is *not* asked. The rail follows the event, and
 * the server derives it from PaymentRails::methodFor() — so this form reads
 * `requires_payment_channel` (that same answer, already published) only to know
 * whether to show the channel picker, and says in one line which rail is in
 * force. A pair of buttons here would have let a gateway event take cash off
 * the books, and would have been a second reader of a rule the server owns.
 */
export function BoxOfficeDialog({
  open,
  categories,
  event,
  pending,
  fieldErrors,
  result,
  checkingIn,
  checkedIn,
  onCheckIn,
  onSellAnother,
  onClose,
  onSubmit,
}: {
  open: boolean;
  /** Every category of this event — including ones whose window has closed. */
  categories: TicketCategory[];
  event: SportEvent;
  pending?: boolean;
  fieldErrors?: FieldErrors;
  /** The order just sold, or null while the form is still being filled. */
  result: TicketSaleResult | null;
  checkingIn?: boolean;
  /** How many tickets the last check-in admitted, or null if none was done. */
  checkedIn: number | null;
  onCheckIn: (orderId: string) => void;
  onSellAnother: () => void;
  onClose: () => void;
  onSubmit: (payload: TicketSalePayload) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={(next) => (next ? undefined : onClose())}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader
          icon={result ? CheckCircle2 : Store}
          title={result ? "Pesanan dibuat" : "Jual tiket di loket"}
          description={
            result
              ? undefined
              : "Terbitkan tiket untuk pembeli yang datang langsung. Jendela penjualan online tidak berlaku di sini."
          }
        />
        {result ? (
          <ResultPane
            result={result}
            checkingIn={checkingIn}
            checkedIn={checkedIn}
            onCheckIn={onCheckIn}
            onSellAnother={onSellAnother}
            onClose={onClose}
          />
        ) : (
          <SaleForm
            categories={categories}
            event={event}
            pending={pending}
            fieldErrors={fieldErrors}
            onClose={onClose}
            onSubmit={onSubmit}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function SaleForm({
  categories,
  event,
  pending,
  fieldErrors,
  onClose,
  onSubmit,
}: {
  categories: TicketCategory[];
  event: SportEvent;
  pending?: boolean;
  fieldErrors?: FieldErrors;
  onClose: () => void;
  onSubmit: (payload: TicketSalePayload) => void;
}) {
  // `is_on_sale` is deliberately not filtered — see the component docblock.
  // Sold out still is: there is nothing to hand over.
  const sellable = categories.filter((c) => !isSoldOut(c));

  const [selected, setSelected] = useState<string>(sellable[0]?.id ?? "");
  const [quantity, setQuantity] = useState(1);
  const [dates, setDates] = useState<string[]>([]);
  const [buyer, setBuyer] = useState({ name: "", email: "", phone: "" });
  const [channel, setChannel] = useState<string | null>(null);

  // The event's rail, read from the answer the server already published rather
  // than recombined here: `requires_payment_channel` *is*
  // `methodFor() === 'gateway'`. Gateway → the buyer pays by QR; otherwise the
  // box office takes cash, because the person is standing here and has nothing
  // to upload.
  const rail: Rail = event.requires_payment_channel ? "gateway" : "onsite";

  const category = sellable.find((c) => c.id === selected) ?? null;
  const orderDates = category ? coveredDates(category, dates) : [];
  const needsDates = category?.day_mode === "per_day";

  const maxQty = Math.max(1, Math.min(20, seatCeiling(category, orderDates)));
  const pricedUnits =
    quantity * pricedDays(category?.day_mode ?? "none", orderDates);
  const total = (category?.price ?? 0) * pricedUnits;
  const requiresChannel = rail === "gateway" && total > 0;

  const canSubmit =
    !!category &&
    quantity > 0 &&
    buyer.name.trim() &&
    buyer.email.trim() &&
    (!needsDates || orderDates.length > 0) &&
    (!requiresChannel || channel);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!category) return;

    onSubmit({
      ticket_category_id: category.id,
      quantity,
      // Only a per-day category's dates are a choice; a pass covers its own and
      // the server resolves that itself.
      dates: needsDates ? orderDates : undefined,
      buyer_name: buyer.name,
      buyer_email: buyer.email,
      buyer_phone: buyer.phone || undefined,
      // No `rail`: the server derives it from the event. Sending it would be a
      // value the API ignores, which is worse than not sending it — it reads
      // like the till decides.
      payment_channel: requiresChannel ? channel! : undefined,
    });
  }

  if (sellable.length === 0) {
    return (
      <>
        <DialogBody className="min-h-0 flex-1">
          <p className="text-sm text-muted-foreground">
            Semua kategori tiket sudah habis terjual. Tambah kuota atau kategori
            baru dulu.
          </p>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Tutup
          </Button>
        </DialogFooter>
      </>
    );
  }

  return (
    // `min-h-0` on both, and it is load-bearing. The panel is a flex column
    // capped at 85vh with `overflow-hidden`, but a flex child's default
    // `min-height: auto` refuses to shrink below its content — so this form
    // grew past the panel and the body never got a height to scroll inside,
    // leaving the footer clipped off with no way to reach it. Same trap
    // point-adjustment-dialog.tsx documents.
    <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
      <DialogBody className="min-h-0 flex-1">
        <div className="grid gap-2">
          <Label htmlFor="bo-category" className="font-semibold">
            Kategori
          </Label>
          <Select
            id="bo-category"
            value={selected}
            onChange={(e) => {
              setSelected(e.target.value);
              // A date one category sells is not necessarily one the next does,
              // and the channel's fee is quoted per ticket — both reset, same
              // as on the buyer's page.
              setQuantity(1);
              setDates([]);
              setChannel(null);
            }}
          >
            {sellable.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} · {c.price > 0 ? rupiah(c.price) : "Gratis"}
                {c.price > 0 && priceUnitLabel(c.day_mode)
                  ? ` ${priceUnitLabel(c.day_mode)}`
                  : ""}
                {c.is_on_sale ? "" : " (penjualan online tutup)"}
              </option>
            ))}
          </Select>
          <FieldError error={fieldErrors?.ticket_category_id} />
        </div>

        {needsDates && category && (
          <div className="grid gap-2">
            <Label className="font-semibold">Tanggal kehadiran</Label>
            <DayPickerChips
              days={selectableDates(category, quantity).map((day) => ({
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
            <p className="text-xs text-muted-foreground">
              Tiap tanggal dihitung satu harga tiket.
            </p>
            <FieldError error={fieldErrors?.dates} />
          </div>
        )}

        {category?.day_mode === "pass" && (
          <div className="grid gap-1">
            <Label className="font-semibold">Tanggal kehadiran</Label>
            <p className="text-sm font-semibold">
              {dateRangeLabel(sellingDates(category))}
            </p>
            <p className="text-xs text-muted-foreground">
              Tiket terusan — satu harga untuk semua hari itu, satu QR per hari.
            </p>
          </div>
        )}

        <div className="grid gap-2">
          <div className="flex items-center justify-between">
            <Label className="font-semibold">Jumlah orang</Label>
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
          {/* Where "6 tiket" comes from, beside the stepper that produced it. */}
          {category && pricedUnits > quantity && (
            <p className="text-sm text-muted-foreground">
              {unitBreakdownLabel(category.day_mode, quantity, orderDates)} ={" "}
              <span className="font-semibold text-foreground">
                {unitCountLabel(category.day_mode, quantity, orderDates)}
              </span>
            </p>
          )}
          <FieldError error={fieldErrors?.quantity} />
        </div>

        <Field
          id="bo-name"
          label="Nama pembeli"
          required
          value={buyer.name}
          onChange={(v) => setBuyer((b) => ({ ...b, name: v }))}
          sanitize={nameInput}
          error={fieldErrors?.buyer_name}
        />
        <Field
          id="bo-email"
          label="Email"
          required
          hint="e-tiket & kwitansi dikirim ke sini"
          value={buyer.email}
          onChange={(v) => setBuyer((b) => ({ ...b, email: v }))}
          inputMode="email"
          error={fieldErrors?.buyer_email}
        />
        <Field
          id="bo-phone"
          label="No. HP"
          hint="opsional"
          value={buyer.phone}
          onChange={(v) => setBuyer((b) => ({ ...b, phone: v }))}
          inputMode="tel"
          sanitize={phoneInput}
          error={fieldErrors?.buyer_phone}
        />

        {/* Stated, not chosen. Which rail is in force is the event's setting,
            and the staff needs to know it — a cash sale is a different act from
            opening a payment, and the next thing they do depends on which one
            this is. */}
        <div className="flex items-start gap-2 border-t border-border pt-4">
          {rail === "gateway" ? (
            <QrCodeIcon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          ) : (
            <Banknote className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          )}
          <div className="min-w-0">
            <p className="text-sm font-semibold">
              {rail === "gateway" ? "Bayar lewat QR" : "Tunai di loket"}
            </p>
            <p className="text-xs text-muted-foreground">
              {rail === "gateway"
                ? "Pembeli scan QR dengan HP-nya sendiri. Status lunas masuk otomatis."
                : "Event ini tidak menerima pembayaran online, jadi uangnya diterima petugas dan pesanan langsung lunas."}
            </p>
          </div>
        </div>

        {requiresChannel && (
          <ChannelPicker
            amount={total}
            audience="ticket"
            // Paid units, not seats: the service fee is per ticket-day.
            units={pricedUnits}
            unitLabel="tiket"
            value={channel}
            onChange={setChannel}
          />
        )}

        {/* ChannelPicker ends in its own fee-inclusive total, so this row only
            appears when there is none to defer to. */}
        {!requiresChannel && (
          <div className="flex items-center justify-between border-t border-border pt-4">
            <span className="text-sm text-muted-foreground">Total</span>
            <span
              className="text-xl font-bold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              {total > 0 ? rupiah(total) : "Gratis"}
            </span>
          </div>
        )}
      </DialogBody>
      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          onClick={onClose}
          disabled={pending}
        >
          Batal
        </Button>
        <Button type="submit" disabled={!canSubmit || pending}>
          {pending && <Loader2 className="h-4 w-4 animate-spin" />}
          {rail === "onsite" ? "Terbitkan tiket" : "Buat pesanan"}
        </Button>
      </DialogFooter>
    </form>
  );
}

/**
 * What to do with the order that was just made.
 *
 * Cash is paid before it exists, so it goes straight to the check-in button.
 * A gateway sale is not, so it gets the QR — and then this pane *waits*, asking
 * the order every few seconds whether the money has arrived, and turns into the
 * paid pane itself when it has.
 *
 * That poll is the whole reason the button is reachable at all. `result` is a
 * snapshot of the moment the sale was made, and a gateway order settles from a
 * webhook some seconds later: reading `result.settled` alone meant the check-in
 * button could never appear for a QR sale, and the staff member watching the
 * buyer tap "pay" had no way to admit them without leaving for the buyer list.
 *
 * Polled through the buyer's own public endpoint rather than a new organizer
 * one — the order id is the only credential that flow has ever needed, and the
 * e-ticket page already reads it the same way. Stops the moment it reads `paid`,
 * so an abandoned payment costs one request every few seconds for as long as
 * the dialog stays open, and nothing after it is closed.
 *
 * `settled` is read rather than the rail throughout, which is what keeps a free
 * gateway ticket — settled on creation, no payment opened — on the paid side.
 */
function ResultPane({
  result,
  checkingIn,
  checkedIn,
  onCheckIn,
  onSellAnother,
  onClose,
}: {
  result: TicketSaleResult;
  checkingIn?: boolean;
  checkedIn: number | null;
  onCheckIn: (orderId: string) => void;
  onSellAnother: () => void;
  onClose: () => void;
}) {
  // Only while there is something to wait for. A cash sale is already paid, and
  // a sale whose payment could not be opened has nothing coming.
  const awaitingPayment = !result.settled && !!result.redirect_url;

  const liveQuery = useQuery({
    queryKey: ["box-office-order", result.order.id],
    queryFn: () => getTicketOrder(result.order.id),
    enabled: awaitingPayment,
    // Four seconds: a QRIS payment lands in a handful, and the person is
    // standing here. Stops itself once the webhook has been through.
    refetchInterval: (query) => (query.state.data?.status === "paid" ? false : 4000),
  });

  // The live row wins once it arrives, so the ticket list and receipt number
  // below are the ones the order actually has — not the ones it had at creation.
  const order = liveQuery.data ?? result.order;
  const paid = result.settled || order.status === "paid";
  const dated = (order.event_dates?.length ?? 0) > 0;

  return (
    <>
      <DialogBody className="min-h-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-semibold">{order.buyer_name}</span>
          {order.category && (
            <Badge variant="neutral">{order.category.name}</Badge>
          )}
          <Badge variant={paid ? "success" : "info"}>
            {paid ? "Lunas" : "Menunggu pembayaran"}
          </Badge>
        </div>
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            {order.tickets?.length ?? 0} tiket
            {dated ? ` · ${dateRangeLabel(order.event_dates!)}` : ""}
          </span>
          <span className="font-semibold">
            {order.total_price > 0 ? rupiah(order.total_price) : "Gratis"}
          </span>
        </div>

        {!paid && result.redirect_url && (
          <div className="grid justify-items-center gap-3 border-t border-border pt-4">
            <QrCode value={result.redirect_url} size={220} />
            <p className="text-center text-sm text-muted-foreground">
              Minta pembeli scan QR ini dengan HP-nya untuk membayar.
            </p>
            {/* Said out loud, because the staff's next move depends on it: stay
                and admit them here, or close and get on with the queue. */}
            <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              Menunggu pembayaran — tombol check-in muncul sendiri begitu lunas.
            </p>
            <div className="flex flex-wrap justify-center gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  window.open(result.redirect_url!, "_blank", "noopener")
                }
              >
                Buka halaman bayar
              </Button>
              <CopyLinkButton
                path={`/tickets/${order.id}`}
                label="Salin tautan tiket"
              />
            </div>
          </div>
        )}

        {!paid && !result.redirect_url && (
          <p className="rounded-md border border-border bg-[var(--bg-soft)] px-3 py-2 text-xs text-muted-foreground">
            Pembayaran tidak bisa dibuka. Tagihannya ada di daftar Pembeli —
            buka dari sana atau batalkan lalu jual ulang dengan tunai.
          </p>
        )}

        {paid && (
          <div className="grid gap-2 border-t border-border pt-4">
            {order.receipt_number && (
              <p className="text-sm text-muted-foreground">
                Kwitansi{" "}
                <span className="font-semibold text-foreground">
                  {order.receipt_number}
                </span>
              </p>
            )}
            {checkedIn === null ? (
              <>
                <Button
                  type="button"
                  onClick={() => onCheckIn(order.id)}
                  disabled={checkingIn}
                  className="w-full"
                >
                  {checkingIn && <Loader2 className="h-4 w-4 animate-spin" />}
                  Check-in sekarang
                </Button>
                <p className="text-xs text-muted-foreground">
                  {dated
                    ? "Hanya tiket yang berlaku hari ini yang dipakai — hari lainnya tetap bisa dipakai nanti."
                    : "Semua tiket pesanan ini langsung ditandai terpakai."}
                </p>
              </>
            ) : (
              <p className="text-sm font-semibold text-[var(--success)]">
                {checkedIn} tiket sudah di-check-in.
              </p>
            )}
          </div>
        )}
      </DialogBody>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onClose}>
          Tutup
        </Button>
        <Button type="button" onClick={onSellAnother}>
          Jual lagi
        </Button>
      </DialogFooter>
    </>
  );
}

function Field({
  id,
  label,
  value,
  onChange,
  required,
  hint,
  error,
  inputMode,
  sanitize,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  hint?: string;
  error?: string;
  inputMode?: ComponentProps<typeof Input>["inputMode"];
  /** Runs on every keystroke to drop characters this field doesn't accept. */
  sanitize?: (v: string) => string;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={id} className="font-semibold">
        {label}
        {required && <span className="text-[var(--danger)]"> *</span>}
        {hint && (
          <span className="font-normal text-muted-foreground"> ({hint})</span>
        )}
      </Label>
      <Input
        id={id}
        value={value}
        inputMode={inputMode}
        onChange={(e) =>
          onChange(sanitize ? sanitize(e.target.value) : e.target.value)
        }
        aria-invalid={!!error}
      />
      <FieldError error={error} />
    </div>
  );
}

function FieldError({ error }: { error?: string }) {
  if (!error) return null;

  return <p className="text-xs text-[var(--danger)]">{error}</p>;
}
