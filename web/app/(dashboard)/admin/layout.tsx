"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { useAuthStore } from "@/stores/auth-store";

/**
 * Restricts the SaaS admin area to super admins. The backend already enforces
 * this on every endpoint; this just keeps non-admins from seeing the UI and
 * sends them back to their own dashboard.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);

  useEffect(() => {
    if (user && user.role !== "super_admin") {
      router.replace("/dashboard");
    }
  }, [user, router]);

  // Wait until the profile (and role) is known before rendering admin UI.
  if (!user || user.role !== "super_admin") {
    return (
      <div className="grid min-h-[60vh] place-items-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" aria-label="Memuat" />
      </div>
    );
  }

  return <>{children}</>;
}
