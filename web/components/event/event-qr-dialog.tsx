"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { Download, QrCode as QrCodeIcon } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTrigger,
  DialogClose,
} from "@/components/ui/dialog";
import { CopyLinkButton } from "@/components/shared/copy-link-button";

/**
 * Poster size, in pixels. A QR that is scanned off a banner is photographed
 * from a couple of metres away, so it is generated once at print resolution and
 * *displayed* scaled down by CSS — the preview and the download are then the
 * same image by construction. Generating a small one to show and a large one to
 * save would be two codes, and nobody would ever notice the saved one pointing
 * somewhere else.
 */
const PRINT_SIZE = 1024;

/** The public URL this code points at, origin included. */
function absoluteUrl(path: string): string {
  const origin =
    process.env.NEXT_PUBLIC_APP_URL ||
    (typeof window !== "undefined" ? window.location.origin : "");

  return `${origin.replace(/\/$/, "")}${path}`;
}

/**
 * The public page of one event, as a QR code to download.
 *
 * Same shape as the e-ticket's QR, with the one difference that matters: a
 * ticket QR carries an opaque token that only the scanner understands, while
 * this carries a plain URL, so any phone camera opens it. That is the whole
 * point — it goes on a poster, a jersey, a banner at the gate.
 *
 * `errorCorrectionLevel: "H"` (the ticket uses "M"): this one gets printed,
 * folded, rained on and photographed at an angle, and at URL length the extra
 * redundancy costs a handful of modules rather than a tier of density.
 */
export function EventQrDialog({
  /** Root-relative path to the public page, e.g. `/garuda/piala-kota`. */
  path,
  /** Shown under the code and used for the file name. */
  eventName,
  /** File name stem, so the saved PNG is not `qrcode.png` among twenty others. */
  fileStem,
}: {
  path: string;
  eventName: string;
  fileStem: string;
}) {
  const [open, setOpen] = useState(false);
  const [src, setSrc] = useState<string | null>(null);

  // Read while open, never on the server: NEXT_PUBLIC_APP_URL is absent in
  // local dev, so the origin has to come from `window` — and the only render
  // that reads it is one the user's own click caused, by which time the dialog
  // is mounted on the client and there is no server render to disagree with.
  const url = open ? absoluteUrl(path) : "";

  useEffect(() => {
    if (!url) return;

    let active = true;

    QRCode.toDataURL(url, { width: PRINT_SIZE, margin: 2, errorCorrectionLevel: "H" })
      .then((data) => {
        if (active) setSrc(data);
      })
      .catch(() => {
        if (active) setSrc(null);
      });

    return () => {
      active = false;
    };
  }, [url]);

  const download = () => {
    if (!src) return;

    const a = document.createElement("a");
    a.href = src;
    a.download = `qr-${fileStem}.png`;
    a.click();

    toast.success("QR Code tersimpan.");
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          <QrCodeIcon className="h-4 w-4" />
          QR Code
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <DialogHeader
          icon={QrCodeIcon}
          title="QR Code event"
          description="Arahkan kamera ke kode ini untuk membuka halaman event."
        />
        <DialogBody className="justify-items-center text-center">
          {src ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={src}
              alt={`QR Code halaman ${eventName}`}
              width={240}
              height={240}
              className="rounded-lg border border-border bg-white p-2"
            />
          ) : (
            <div className="grid h-[240px] w-[240px] place-items-center rounded-lg bg-[var(--bg-soft)] text-xs text-muted-foreground">
              QR…
            </div>
          )}
          <div>
            <div className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
              {eventName}
            </div>
            {/* The URL in full, because a poster proof is checked by reading it.
                break-all: an event slug has no spaces to wrap at. */}
            <code className="mt-1 block break-all text-[11px] text-muted-foreground">{url}</code>
          </div>
        </DialogBody>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="ghost" size="sm">
              Tutup
            </Button>
          </DialogClose>
          <CopyLinkButton path={path} />
          <Button size="sm" onClick={download} disabled={!src}>
            <Download className="h-4 w-4" />
            Unduh PNG
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
