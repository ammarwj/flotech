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
      className="mb-4 flex flex-col gap-3 p-3 sm:mb-5 sm:flex-row sm:items-center sm:p-4"
      style={{
        borderColor: `color-mix(in srgb, var(--warning) 45%, transparent)`,
        background: `color-mix(in srgb, var(--warning) 8%, transparent)`,
      }}
    >
      <div className="flex min-w-0 flex-1 items-start gap-2.5 sm:gap-3">
        <UserCog className="mt-0.5 h-4 w-4 shrink-0 text-[var(--warning)] sm:h-5 sm:w-5" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold sm:text-base">
            Login sebagai {user?.full_name || user?.email || "user ini"}
          </p>
          {/* Detail panjang hanya di layar lebar: di mobile kartunya jadi setinggi
              setengah viewport dan tombolnya terdorong jauh ke bawah. */}
          <p className="mt-0.5 hidden text-sm text-muted-foreground sm:block">
            Semua tindakan di sini tercatat atas nama user tersebut.
          </p>
        </div>
      </div>
      <Button
        size="sm"
        className="w-full shrink-0 sm:w-auto"
        onClick={handleReturn}
        disabled={pending}
      >
        {pending ? "Kembali…" : "Kembali ke admin"}
      </Button>
    </Card>
  );
}
