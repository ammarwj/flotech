"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { LogOut } from "lucide-react";
import { toast } from "sonner";

import { logout as apiLogout } from "@/lib/api/auth";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";

/** Shows the signed-in user and a logout action in the dashboard header. */
export function UserMenu() {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const clearAuth = useAuthStore((s) => s.clearAuth);
  const [pending, setPending] = useState(false);

  const handleLogout = async () => {
    setPending(true);
    try {
      await apiLogout(); // revokes refresh token + clears the HttpOnly cookie
    } catch {
      // Even if the call fails, drop local auth so the user is signed out.
    }
    clearAuth();
    toast.success("Berhasil keluar");
    router.replace("/login");
  };

  return (
    <div className="flex items-center gap-3">
      {user && (
        <div className="hidden text-right leading-tight sm:block">
          <div className="text-sm font-semibold">{user.full_name}</div>
          <div className="text-xs text-muted-foreground">{user.email}</div>
        </div>
      )}
      <Button variant="outline" size="sm" onClick={handleLogout} disabled={pending}>
        <LogOut className="h-4 w-4" />
        {pending ? "Keluar…" : "Keluar"}
      </Button>
    </div>
  );
}
