"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { parseApiError } from "@/lib/api/errors";
import { requestRaceEntryRefund, startRaceTransfer } from "@/lib/api/race";

/**
 * The two things a runner can do to their own entry: ask for the fee back, and
 * move category.
 *
 * A hook because there are now TWO surfaces offering them — the public proof
 * page and the participant dashboard's list — and the invalidation is the part
 * worth not writing twice. A category move changes the entry, the quota of two
 * categories and the cached transfer options; a copy that forgets one of those
 * leaves a list showing the old category beside a dialog offering to move to
 * it again.
 *
 * It deliberately does NOT cover the three doors that belong to an open move
 * (pay, abandon, upload proof). Those live on the proof page because that is
 * where the panel reporting the move lives, and a list row has nowhere to put
 * a bank account and an upload field. The list links there instead.
 */
export function useRaceEntryActions(entryId: string | null) {
  const qc = useQueryClient();

  /**
   * Both the single-entry page and the list, every time.
   *
   * The list is the one that is easy to forget and the one that shows the
   * stale answer longest: a runner moves category from the dashboard, the row
   * behind the dialog keeps its old category and old total, and nothing on
   * screen says which is right.
   */
  const invalidate = () => {
    if (entryId) {
      qc.invalidateQueries({ queryKey: ["race-entry", entryId] });
      qc.invalidateQueries({ queryKey: ["race-transfer-options", entryId] });
    }
    qc.invalidateQueries({ queryKey: ["my-race-entries"] });
  };

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
    mutationFn: (reason: string) => requestRaceEntryRefund(entryId!, reason),
    onSuccess: () => {
      invalidate();
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
   * A paid top-up hands off to Midtrans; everything else stays where it is —
   * an even or downward move is already applied when the response lands, and a
   * manual one uploads its receipt on the proof page.
   *
   * `mock` is checked before `redirect_url` for the reason written in
   * `checkoutOutcome()`: a Midtrans error also returns a null url, so the two
   * must not be read as one signal.
   */
  const transfer = useMutation({
    mutationFn: (input: { categoryId: string; reason: string | null; channel: string | null }) =>
      startRaceTransfer(entryId!, {
        race_category_id: input.categoryId,
        reason: input.reason,
        payment_channel: input.channel,
      }),
    onSuccess: (res) => {
      if (!res.mock && res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }

      invalidate();
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

  return { refund, transfer };
}
