"use client";

import { useCallback } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * Query string sebagai state UI. Dipakai untuk state yang harus selamat dari
 * refresh & back/forward (tab aktif, kategori terpilih) — bukan untuk state
 * sesaat seperti dialog yang sedang terbuka.
 *
 * Komponen yang memakainya wajib berada di dalam <Suspense>: useSearchParams()
 * di client component gagal saat `next build` tanpa itu.
 */
export function useUrlState() {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();

  const setParams = useCallback(
    (patch: Record<string, string | undefined>) => {
      const next = new URLSearchParams(params.toString());

      for (const [key, value] of Object.entries(patch)) {
        if (!value) next.delete(key);
        else next.set(key, value);
      }

      const qs = next.toString();
      // scroll: false — mengganti tab tidak boleh melompat ke atas halaman.
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [params, pathname, router]
  );

  return { params, setParams };
}
