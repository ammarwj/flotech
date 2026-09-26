"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { dashboardModeFor, MODE_HOME } from "@/lib/hooks/use-dashboard-mode";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Restricts the SaaS admin area to super admins. The backend already enforces
 * this on every endpoint; this just keeps non-admins from seeing the UI and
 * sends them back to their own dashboard.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);

  // Ke mode milik akun yang sekarang dipegang, bukan `/organizer` mati.
  //
  // Satu-satunya cara masuk ke sini bukan sebagai super admin adalah
  // "Login sebagai": `startImpersonation()` menukar `user` di store **selagi
  // admin masih berdiri di /admin/users**, jadi efek ini menyala pada tick yang
  // sama dengan `router.push()` milik halaman itu — dan `replace` dari layout
  // memenangi lombanya. Dengan `/organizer` hardcoded, akun petugas (tanpa
  // organisasi) diteruskan OrganizerLayout ke /onboarding, yang berada di route
  // group-nya sendiri tanpa AuthGate dan tanpa ImpersonationBanner: tidak ada
  // "Kembali ke admin" di sana. Rantai itu sudah tertulis di
  // ImpersonationBanner ("/admin → /organizer → /onboarding"); ini ujung yang
  // menerbitkannya.
  //
  // Bukan "jangan pindah selama impersonating": halaman admin memang tidak
  // boleh dirender oleh token role "user" — API-nya 403 dan UI-nya kosong.
  // Yang diperbaiki tujuannya, bukan kepindahannya, sehingga siapa pun yang
  // menang lomba mendarat di alamat yang sama.
  useEffect(() => {
    if (user && user.role !== "super_admin") {
      router.replace(MODE_HOME[dashboardModeFor(user)]);
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
