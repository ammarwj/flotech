"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Check, Link2 } from "lucide-react";

import { Button } from "@/components/ui/button";

/**
 * Copies an absolute URL to the clipboard, confirming inline for a moment.
 *
 * Takes a path and prefixes it here rather than asking each caller to build the
 * absolute URL: a link that is meant to be pasted to someone else has to carry
 * the origin, and half the surfaces that hand out links would otherwise forget.
 *
 * `navigator.clipboard` needs a secure context — it is absent over plain HTTP
 * on a LAN address, so the failure is reported rather than silently swallowed.
 */
export function CopyLinkButton({
  path,
  label = "Salin tautan",
  copiedLabel = "Tersalin",
  size = "sm",
  variant = "outline",
}: {
  /** Root-relative, e.g. `/tickets/abc`. */
  path: string;
  label?: string;
  copiedLabel?: string;
  size?: "sm" | "default";
  variant?: "outline" | "secondary" | "ghost";
}) {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    const origin =
      process.env.NEXT_PUBLIC_APP_URL ||
      (typeof window !== "undefined" ? window.location.origin : "");

    try {
      await navigator.clipboard.writeText(`${origin}${path}`);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      toast.error("Gagal menyalin tautan.");
    }
  };

  return (
    <Button type="button" variant={variant} size={size} onClick={copy}>
      {copied ? <Check className="h-4 w-4" /> : <Link2 className="h-4 w-4" />}
      {copied ? copiedLabel : label}
    </Button>
  );
}
