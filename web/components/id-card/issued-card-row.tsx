"use client";

import { Download, ExternalLink, Loader2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { IdCardIssued } from "@/types/api";

/**
 * One issued card in the history list.
 *
 * Three delivery states, not two, and the third is the one an organizer acts
 * on: "Tanpa email" means this card has to be printed and handed over, because
 * the team registered without a manager account and a player has no address of
 * their own. Collapsing it into "Belum dikirim" would leave somebody waiting
 * for a mail that can never be queued.
 *
 * The verify link opens the page the printed QR opens — the only way to check
 * from a desk that the code on the plastic resolves, short of fetching a phone.
 */
export function IssuedCardRow({
  card,
  onDownload,
  downloading = false,
}: {
  card: IdCardIssued;
  onDownload: (cardId: string) => void;
  downloading?: boolean;
}) {
  const subtitle = [card.role_label, card.team_name].filter(Boolean).join(" · ");

  return (
    /**
     * Stacked on a phone, one row from `sm` up.
     *
     * Side by side at 390px the badge and the two icon buttons take 175px of
     * the 308px available — 57% of the row — and the name, the only reason
     * anyone reads this list, truncates after two words. Stacking gives it the
     * full width and costs one line; the controls keep their own row, which is
     * also what makes them comfortable to tap.
     */
    <div className="flex flex-col gap-1 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium">{card.recipient_name}</p>
        <p className="truncate text-xs text-muted-foreground">
          {subtitle && <>{subtitle} · </>}
          <code className="text-[11px]">{card.card_number}</code>
        </p>
      </div>

      <div className="-my-1 flex shrink-0 items-center gap-1 sm:my-0">
        {card.sent_at ? (
          <Badge variant="success">Terkirim</Badge>
        ) : card.recipient_email ? (
          <Badge variant="neutral">Belum dikirim</Badge>
        ) : (
          <Badge variant="warning">Tanpa email</Badge>
        )}

        {/* Pushed to the far edge on a phone, where the badge starts the row
            and the two actions read as a pair against the margin. Square and
            44px there: `size="sm"` is 32×36, which is under every tap-size
            guideline and these are the row's only controls. */}
        <span className="ml-auto flex items-center gap-1 sm:ml-0">
          <Button
            asChild
            size="sm"
            variant="ghost"
            title="Buka halaman verifikasi"
            className="h-11 w-11 p-0 sm:h-9 sm:w-auto sm:px-3"
          >
            <a href={card.verify_url} target="_blank" rel="noopener noreferrer">
              <ExternalLink className="h-4 w-4" />
              <span className="sr-only">Buka halaman verifikasi</span>
            </a>
          </Button>

          {card.has_png && (
            <Button
              size="sm"
              variant="ghost"
              title="Unduh PNG kartu ini"
              onClick={() => onDownload(card.id)}
              disabled={downloading}
              className="h-11 w-11 p-0 sm:h-9 sm:w-auto sm:px-3"
            >
              {downloading ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Download className="h-4 w-4" />
              )}
              <span className="sr-only">Unduh PNG kartu ini</span>
            </Button>
          )}
        </span>
      </div>
    </div>
  );
}
