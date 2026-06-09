"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";

/**
 * Renders a QR code for an opaque ticket token as a PNG data URL.
 */
export function QrCode({ value, size = 200 }: { value: string; size?: number }) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    QRCode.toDataURL(value, { width: size, margin: 1, errorCorrectionLevel: "M" })
      .then((url) => {
        if (active) setSrc(url);
      })
      .catch(() => {
        if (active) setSrc(null);
      });
    return () => {
      active = false;
    };
  }, [value, size]);

  if (!src) {
    return (
      <div
        className="grid place-items-center rounded-lg bg-[var(--bg-soft)] text-xs text-muted-foreground"
        style={{ width: size, height: size }}
      >
        QR…
      </div>
    );
  }

  // eslint-disable-next-line @next/next/no-img-element
  return <img src={src} alt="QR Code tiket" width={size} height={size} className="rounded-lg" />;
}
