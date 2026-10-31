"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { UserCog } from "lucide-react";

import { refreshAccessToken } from "@/lib/api/client";
import { me as fetchMe } from "@/lib/api/auth";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";

/**
 * Shown while a super admin is "logged in as" another user.
 *
 * Mounted in the dashboard layout rather than the /admin group on purpose: the
 * impersonated account has role "user", so admin/layout.tsx bounces it out to
 * /organizer — a banner living under /admin would vanish exactly when it is
 * needed. Without it there is no visible sign you are acting as someone else.
 *
 * It sits *above everything*, sidebar included, rather than at the top of
 * `main`: this is a statement about the whole session, not about the page being
 * viewed, and a card inside the content scrolled away the moment anyone started
 * working — which is exactly when forgetting whose account this is costs
 * something. A strip, not a card, for the same reason the header is 64px: it is
 * chrome, and it must not push the page down on every screen.
 *
 * **`.impersonation-strip` is load-bearing**, not styling. The sidebar and the
 * header are sticky too, and they have to start *below* this strip when it is
 * there and at 0 when it is not — so `.dash:has(> .impersonation-strip)` in
 * globals.css sets `--chrome-top`, which both of them offset by. Renaming the
 * class, or wrapping this in another element, leaves them sliding underneath it.
 * Its height is pinned (`h-9` = `--chrome-top`); one line, never two.
 */
export function ImpersonationBanner() {
  const router = useRouter();
  const qc = useQueryClient();
  const impersonating = useAuthStore((s) => s.impersonating);
  const user = useAuthStore((s) => s.user);
  const setAuth = useAuthStore((s) => s.setAuth);
  const setAccessToken = useAuthStore((s) => s.setAccessToken);
  const stopImpersonation = useAuthStore((s) => s.stopImpersonation);
  const clearAuth = useAuthStore((s) => s.clearAuth);
  const [pending, setPending] = useState(false);

  if (!impersonating) return null;

  const handleReturn = async () => {
    setPending(true);
    try {
      // The admin's refresh cookie was never touched by the impersonation, so
      // exchanging it hands back the admin's own session — no re-login needed.
      const token = await refreshAccessToken();
      if (!token) {
        clearAuth();
        router.replace("/login");
        return;
      }

      // Must land in the store *before* me(): the request interceptor reads the
      // token from there, so calling me() first would still use the
      // impersonation token and hand back the impersonated user — pairing the
      // admin's token with role "user", which bounced /admin → /organizer →
      // /onboarding.
      setAccessToken(token);

      const admin = await fetchMe();
      setAuth(token, admin);
      stopImpersonation();
      // The impersonated user's cached queries must not follow the admin back.
      qc.clear();
      // replace, so Back doesn't return to a page rendered as the other user.
      router.replace("/admin");
    } catch {
      toast.error("Gagal kembali ke akun admin. Coba lagi.");
    } finally {
      setPending(false);
    }
  };

  return (
    <div
      className="impersonation-strip sticky top-0 z-40 flex h-9 items-center gap-2.5 border-b px-5 md:col-span-2 md:px-6"
      style={{
        borderColor: `color-mix(in srgb, var(--warning) 45%, transparent)`,
        // Mixed into `--surface`, not into `transparent`: this is sticky chrome
        // and the page scrolls underneath it, so a tint that is 88% see-through
        // shows the content sliding through the words. The header next to it
        // gets away with translucency because it also carries backdrop-blur;
        // one row of 14px text has no room to blur anything legibly.
        background: `color-mix(in srgb, var(--warning) 14%, var(--surface))`,
      }}
    >
      <UserCog className="h-4 w-4 shrink-0 text-[var(--warning)]" />
      {/* One line, truncated: the strip is chrome and must stay one row tall at
          375px, where the name alone can outrun the width. */}
      <p className="min-w-0 flex-1 truncate text-sm">
        <span className="font-semibold">Login sebagai </span>
        {user?.full_name || user?.email || "user ini"}
      </p>
      <Button
        size="sm"
        className="h-7 shrink-0 px-3"
        onClick={handleReturn}
        disabled={pending}
      >
        {pending ? "Kembali…" : "Kembali ke admin"}
      </Button>
    </div>
  );
}
