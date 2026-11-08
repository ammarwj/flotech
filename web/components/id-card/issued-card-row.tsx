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
    <div className="flex items-center justify-between gap-3 py-2.5">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium">{card.recipient_name}</p>
        <p className="truncate text-xs text-muted-foreground">
          {subtitle && <>{subtitle} · </>}
          <code className="text-[11px]">{card.card_number}</code>
        </p>
      </div>

      <div className="flex shrink-0 items-center gap-1">
        {card.sent_at ? (
          <Badge variant="success">Terkirim</Badge>
        ) : card.recipient_email ? (
          <Badge variant="neutral">Belum dikirim</Badge>
        ) : (
          <Badge variant="warning">Tanpa email</Badge>
        )}

        <Button asChild size="sm" variant="ghost" title="Buka halaman verifikasi">
          <a href={card.verify_url} target="_blank" rel="noopener noreferrer">
            <ExternalLink className="h-4 w-4" />
          </a>
        </Button>

        {card.has_png && (
          <Button
            size="sm"
            variant="ghost"
            title="Unduh PNG kartu ini"
            onClick={() => onDownload(card.id)}
            disabled={downloading}
          >
            {downloading ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Download className="h-4 w-4" />
            )}
          </Button>
        )}
      </div>
    </div>
  );
}
