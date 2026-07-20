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
import { Card } from "@/components/ui/card";

/**
 * Shown while a super admin is "logged in as" another user.
 *
 * Mounted in the dashboard layout rather than the /admin group on purpose: the
 * impersonated account has role "user", so admin/layout.tsx bounces it out to
 * /organizer — a banner living under /admin would vanish exactly when it is
 * needed. Without it there is no visible sign you are acting as someone else.
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
    <Card
      className="mb-5 flex flex-wrap items-start gap-3 p-4"
      style={{
        borderColor: `color-mix(in srgb, var(--warning) 45%, transparent)`,
        background: `color-mix(in srgb, var(--warning) 8%, transparent)`,
      }}
    >
      <UserCog className="mt-0.5 h-5 w-5 shrink-0 text-[var(--warning)]" />
      <div className="min-w-0 flex-1">
        <p className="font-semibold">
          Kamu sedang login sebagai {user?.full_name || user?.email || "user ini"}
        </p>
        <p className="mt-1 text-sm text-muted-foreground">
          Semua tindakan di sini tercatat atas nama user tersebut. Gunakan tombol di samping untuk
          kembali ke akun admin.
        </p>
      </div>
      <Button size="sm" onClick={handleReturn} disabled={pending}>
        {pending ? "Kembali…" : "Kembali ke admin"}
      </Button>
    </Card>
  );
}
