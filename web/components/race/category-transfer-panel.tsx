"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeftRight, Clock } from "lucide-react";

import { getRaceTransferOptions } from "@/lib/api/race";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
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
import { ChannelPicker } from "@/components/payment/channel-picker";
import type { RaceEntry, RaceTransferOption } from "@/types/api";

/**
 * The runner's own door out of the wrong category.
 *
 * Two shapes in one component, and they are one conversation read in sequence —
 * the same call `RefundRequestPanel` makes: either a move is open and waiting on
 * money, or there is a button to start one. They cannot both be true, because
 * the server refuses a second move while the first is outstanding.
 *
 * **Whether the button may be shown at all is `entry.can_transfer_category`, the
 * SERVER's answer.** Rebuilding it here from `status`/`checked_in_at` would be
 * two readers of a five-part rule — the real one also asks about an open move
 * and a surviving sale window — and the drift shows up as a button that submits
 * into a 422. The `effective_payment_method` lesson, and the component never
 * recomputes it.
 *
 * It renders NOTHING when there is neither an open move to report nor a button
 * to offer: a card explaining that moving categories exists, on an entry that
 * cannot, is an announcement about a door that is not there — the call
 * `online_registration_enabled` already makes on the public event page.
 *
 * **The prices are the entry's own sale window, not today's**, which is why
 * every row prints `period_name`. A runner who bought in Early Bird moves at
 * Early Bird prices, and without that label the two figures cannot be read
 * against any price list the page is showing them.
 */
export function CategoryTransferPanel({
  entry,
  pending,
  onSubmit,
  onPay,
  onCancel,
  dialogOnly,
  open: openProp,
  onOpenChange,
}: {
  entry: RaceEntry;
  pending: boolean;
  onSubmit: (input: { categoryId: string; reason: string | null; channel: string | null }) => void;
  /**
   * Finish paying for a move already open, and give up on one.
   *
   * Optional, so a surface that only offers starting a move (an organizer list,
   * say) renders the status card without controls it cannot serve. The page
   * that owns the proof view passes both: without them the card reports a debt
   * with no way to settle it and no way to release the place it is holding.
   */
  onPay?: (transferId: string, channel: string) => void;
  onCancel?: (transferId: string) => void;
  /**
   * Controlled mode — see `RefundRequestPanel`, which takes the same pair for
   * the same reason. Passing `open` also suppresses this panel's own trigger,
   * because a caller that owns the dialog owns the button that opens it. The
   * status card above is unaffected: that is news, not a control.
   */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /**
   * Render the dialog alone, without the open-move card above it.
   *
   * For a caller already saying the state in its own layout — see the twin
   * prop on `RefundRequestPanel`. A caller using this also gets no "Bayar
   * selisih" / "Batalkan pindah" buttons, because those live on that card;
   * finishing an open move stays on the proof page, which is where the bank
   * account and the upload field are.
   */
  dialogOnly?: boolean;
}) {
  const [openSelf, setOpenSelf] = useState(false);
  const controlled = openProp !== undefined;
  const open = controlled ? openProp : openSelf;
  const setOpen = controlled ? (onOpenChange ?? (() => {})) : setOpenSelf;
  // The reopen dialog's own state, kept apart from the start dialog's: they are
  // two different questions about two different rows, and sharing `picked`
  // would seed one with the other's answer.
  const [payOpen, setPayOpen] = useState(false);
  const [payChannel, setPayChannel] = useState<string | null>(null);
  const [picked, setPicked] = useState<string | null>(null);
  const [channel, setChannel] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);

  const openTransfer = entry.open_transfer ?? null;

  /**
   * The options, fetched only once the dialog is actually open.
   *
   * `enabled` on `open` rather than on the panel mounting: this is one request
   * per category plus a price lookup each, and the proof page polls every four
   * seconds while an entry is pending. A runner who never opens the dialog
   * should cost nothing.
   */
  const options = useQuery({
    queryKey: ["race-transfer-options", entry.id],
    queryFn: () => getRaceTransferOptions(entry.id),
    enabled: open,
  });

  // Nothing to report and nothing to offer. See the docblock.
  // `dialogOnly` is exempt for the reason written on the twin in
  // RefundRequestPanel: the caller already decided there is something to
  // offer, and the card this test guards is not rendered there anyway.
  if (!dialogOnly && !openTransfer && !entry.can_transfer_category) return null;

  const chosen = options.data?.options.find((o) => o.category.id === picked) ?? null;
  // Only an upward move is ever paid for. An even or downward one is settled by
  // the server inside the same request, so asking for a channel there would be
  // a question about a payment that never happens.
  const payable = chosen?.direction === "up" ? chosen.amount : 0;

  const submit = () => {
    if (!picked) {
      setError("Pilih kategori tujuan.");
      return;
    }

    if (payable > 0 && !channel) {
      setError("Pilih metode pembayaran.");
      return;
    }

    setError(null);
    onSubmit({ categoryId: picked, reason: reason.trim() || null, channel });
    setOpen(false);
    setPicked(null);
    setChannel(null);
    setReason("");
  };

  return (
    <>
      {/* ---- A move already waiting on money ----
          Its own card rather than a line inside the status banner: the banner
          says what the ENTRY is, and the entry is still fully paid in its
          original category. This says what is outstanding, which is a different
          sentence about a different row. */}
      {!dialogOnly && openTransfer && (
        <Card
          className="mb-6 flex items-start gap-3 p-5"
          style={{
            borderColor: "var(--warning)",
            background: "color-mix(in srgb, var(--warning) 8%, transparent)",
          }}
        >
          <Clock className="mt-0.5 h-6 w-6 shrink-0 text-[var(--warning)]" />
          <div className="min-w-0">
            <h2 className="text-sm font-bold" style={{ fontFamily: "var(--font-display)" }}>
              {openTransfer.awaiting_verification
                ? "Bukti pindah kategori menunggu verifikasi"
                : "Menunggu pembayaran selisih kategori"}
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Kamu mengajukan pindah ke{" "}
              <strong>{openTransfer.to_category ?? "kategori lain"}</strong>. Kategorinya
              berpindah setelah selisih{" "}
              <strong className="tabular-nums">{rupiah(openTransfer.gross_amount)}</strong>{" "}
              {openTransfer.awaiting_verification ? "diverifikasi penyelenggara" : "dibayar"}.
            </p>
            {/* Said explicitly, because it is the thing a runner assumes wrong:
                their place in the ORIGINAL category is still theirs until this
                settles, which is exactly why the server holds it. */}
            <p className="mt-1.5 text-xs text-muted-foreground">
              Sampai itu terjadi, kamu tetap terdaftar di {entry.category?.name ?? "kategori sekarang"}.
            </p>
            {openTransfer.rejected_reason && (
              <p className="mt-1.5 text-xs text-[var(--danger)]">
                Bukti sebelumnya ditolak: {openTransfer.rejected_reason}
              </p>
            )}

            {/* The way out of the dead end this card used to be.

                A move started on the gateway rail and abandoned left the runner
                here with a sentence and no button: the Snap token expires after
                about a day, so the original tab cannot be returned to, and the
                hourly sweep that would release the held place only reaches the
                manual rail. Gated on the rail rather than on `onPay` being
                passed, because a manual top-up is paid by uploading proof in
                the panel below — two payment affordances for one debt is the
                state nothing on screen could explain.

                Giving up sits beside it and is deliberately plain: it releases
                the place the move is holding in the target category, which is
                the other thing nobody could do from here. */}
            {!openTransfer.awaiting_verification && (
              <div className="mt-3 flex flex-wrap items-center gap-1">
                {openTransfer.payment_method !== "manual" && onPay && (
                  <Button type="button" size="sm" onClick={() => setPayOpen(true)}>
                    Bayar selisih
                  </Button>
                )}
                {onCancel && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground"
                    onClick={() => onCancel(openTransfer.id)}
                    disabled={pending}
                  >
                    Batalkan pindah
                  </Button>
                )}
              </div>
            )}
          </div>
        </Card>
      )}

      {/* Picking a channel again, because the fee is a percentage of the
          difference and the first choice was never re-quoted. Its own dialog
          rather than inline in the card: the card is a status card on a page
          that polls, and a half-filled form inside one is a form that can be
          replaced mid-typing. */}
      <Dialog open={payOpen} onOpenChange={setPayOpen}>
        <DialogContent>
          <DialogHeader
            icon={ArrowLeftRight}
            title="Bayar selisih kategori"
            description={
              openTransfer?.to_category
                ? `Pindah ke ${openTransfer.to_category}. Harganya tidak berubah dari saat kamu mengajukan.`
                : "Harganya tidak berubah dari saat kamu mengajukan."
            }
          />
          <DialogBody>
            {openTransfer && (
              <ChannelPicker
                amount={openTransfer.amount}
                audience="race_entry"
                value={payChannel}
                onChange={(next) => {
                  setPayChannel(next);
                  if (error) setError(null);
                }}
              />
            )}
            <FieldError message={error ?? undefined} />
          </DialogBody>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setPayOpen(false)}>
              Batal
            </Button>
            <Button
              type="button"
              disabled={pending}
              onClick={() => {
                if (!payChannel) {
                  setError("Pilih metode pembayaran.");
                  return;
                }

                setError(null);
                onPay?.(openTransfer!.id, payChannel);
                setPayOpen(false);
              }}
            >
              {pending ? "Memproses…" : "Lanjut bayar"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ---- Start one ----
          Ghost weight, like the refund button beside it and for the same
          reason: this is a correction, not the main path, and giving it the
          weight of "Bayar sekarang" puts it in front of somebody who only came
          to check their BIB. */}
      {!controlled && entry.can_transfer_category && (
        <div className="mb-6 text-center">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setOpen(true)}
            className="text-muted-foreground"
          >
            <ArrowLeftRight className="mr-1.5 h-3.5 w-3.5" />
            Pindah kategori
          </Button>
        </div>
      )}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader
            icon={ArrowLeftRight}
            title="Pindah kategori"
            description="Harganya memakai periode saat kamu mendaftar, bukan harga sekarang."
          />
          <DialogBody>
            {options.isLoading && (
              <p className="text-sm text-muted-foreground">Memuat kategori…</p>
            )}

            {options.isError && (
              <p className="text-sm text-[var(--danger)]">
                Pilihan kategori gagal dimuat. Coba tutup dan buka lagi.
              </p>
            )}

            {/* An empty list is its own sentence, not a silent gap: the server
                leaves out every category that is full, inactive, or was not on
                sale in this runner's window, so "none" is a real answer and the
                runner needs to know that rather than see a form that cannot be
                submitted. */}
            {options.data?.options.length === 0 && (
              <p className="text-sm text-muted-foreground">
                Tidak ada kategori lain yang bisa kamu pindahi saat ini — yang lain sudah
                penuh, tidak aktif, atau tidak dijual pada periode pendaftaranmu.
              </p>
            )}

            {!!options.data?.options.length && (
              <div className="grid gap-2">
                <span className="text-sm font-medium">Kategori tujuan</span>
                {options.data.options.map((option) => (
                  <OptionRow
                    key={option.category.id}
                    option={option}
                    selected={picked === option.category.id}
                    onSelect={() => {
                      setPicked(option.category.id);
                      // Cleared on every change, and that is load-bearing: the
                      // difference is the amount a percentage fee is taken
                      // from, so a channel picked for the previous category
                      // would quote a fee against a number nobody is charging.
                      // The same rule every `onApply` in this codebase follows.
                      setChannel(null);
                      if (error) setError(null);
                    }}
                  />
                ))}
              </div>
            )}

            {/* Only when something is actually owed. `payable > 0` rather than
                "is this an upgrade": an even move is an upgrade in direction
                and costs nothing. */}
            {payable > 0 && (
              <ChannelPicker
                amount={payable}
                audience="race_entry"
                value={channel}
                onChange={(next) => {
                  setChannel(next);
                  if (error) setError(null);
                }}
              />
            )}

            {chosen && (
              <div>
                <Label htmlFor="transfer-reason">Alasan (opsional)</Label>
                <Textarea
                  id="transfer-reason"
                  rows={2}
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  maxLength={500}
                  placeholder="Misalnya: salah pilih jarak saat mendaftar."
                />
              </div>
            )}

            <FieldError message={error ?? undefined} />

            {/* The consequences, said BEFORE the click. The bib is the one
                nobody expects: it carries the old category's code, so it has to
                be reissued — and a runner who has already printed theirs needs
                to know that now, not at the race-pack desk. */}
            {chosen && (
              <p className={dialogConsequences.default}>
                {chosen.direction === "up" ? (
                  <>
                    Pindah setelah selisihnya dibayar; sampai itu kamu tetap di{" "}
                    {entry.category?.name ?? "kategori sekarang"}.
                  </>
                ) : chosen.direction === "down" ? (
                  <>
                    Pindah sekarang. Selisih {rupiah(chosen.amount)} dikembalikan lewat
                    transfer manual, tidak otomatis.
                  </>
                ) : (
                  <>Harganya sama, jadi langsung pindah tanpa pembayaran.</>
                )}{" "}
                BIB diterbitkan ulang
                {entry.bib_number ? ` (sekarang ${entry.bib_number})` : ""}; QR check-in tetap.
              </p>
            )}
          </DialogBody>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button type="button" onClick={submit} disabled={pending || !chosen}>
              {pending ? "Memproses…" : payable > 0 ? "Lanjut bayar selisih" : "Pindah kategori"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

/**
 * One category on offer, with the arithmetic spelled out.
 *
 * The three numbers are printed rather than just the difference, because a
 * difference on its own cannot be checked: a runner holding a receipt for
 * Rp 100.000 needs to see that figure beside the target price to believe the
 * Rp 60.000 between them. `paid_before` is everything they have handed over —
 * their entry plus every settled move — which is also why a second move is
 * never charged the first one again.
 */
function OptionRow({
  option,
  selected,
  onSelect,
}: {
  option: RaceTransferOption;
  selected: boolean;
  onSelect: () => void;
}) {
  const tone =
    option.direction === "up"
      ? "text-foreground"
      : option.direction === "down"
        ? "text-[var(--success)]"
        : "text-muted-foreground";

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        "flex w-full items-start justify-between gap-3 rounded-lg border p-3 text-left transition-colors",
        selected
          ? "border-[var(--brand-600)] bg-[color-mix(in_srgb,var(--brand-600)_6%,transparent)]"
          : "border-border hover:bg-[var(--bg-soft)]"
      )}
    >
      <span className="min-w-0">
        <span className="block text-sm font-medium">{option.category.name}</span>
        <span className="mt-0.5 block text-xs text-muted-foreground">
          {rupiah(option.to_price)} · periode {option.period_name} · sudah dibayar{" "}
          {rupiah(option.paid_before)}
        </span>
        {/* Only when there is a real cap and it is getting tight. A number over
            an unlimited category would be noise, and the server has already
            left out the ones that are full. */}
        {option.category.remaining !== null && option.category.remaining <= 10 && (
          <span className="mt-0.5 block text-xs text-[var(--warning)]">
            Sisa {option.category.remaining} tempat
          </span>
        )}
      </span>
      <span className={cn("shrink-0 text-right text-sm font-semibold tabular-nums", tone)}>
        {option.direction === "up" && `+ ${rupiah(option.amount)}`}
        {option.direction === "down" && `− ${rupiah(option.amount)}`}
        {option.direction === "even" && "Gratis"}
      </span>
    </button>
  );
}
