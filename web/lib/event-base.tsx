"use client";

import { createContext, useContext } from "react";
import { useParams } from "next/navigation";

/**
 * Prefix untuk tautan internal halaman event, plus tautan balik ke platform.
 *
 * Halaman event dilayani dari dua host, dan bentuk URL-nya berbeda: di
 * `floevent.id` semua tautannya berawalan `/{org}/{event}`, sedangkan di custom
 * domain event itu **adalah** root sehingga awalannya harus kosong. Middleware
 * yang tahu bedanya (`x-flo-custom-domain`); ini yang membawanya ke komponen.
 *
 * Providernya server component (`[eventSlug]/layout.tsx`) dan bukan pengintipan
 * `window.location` di klien: deteksi di klien menyebabkan hydration mismatch —
 * render pertama di server tidak tahu hostname.
 */

const CustomDomainContext = createContext<string | null>(null);

export function EventBaseProvider({
  customDomain,
  children,
}: {
  customDomain: string | null;
  children: React.ReactNode;
}) {
  return (
    <CustomDomainContext.Provider value={customDomain}>{children}</CustomDomainContext.Provider>
  );
}

const APP_URL = (process.env.NEXT_PUBLIC_APP_URL ?? "https://floevent.id").replace(/\/$/, "");

export function useEventBase() {
  const customDomain = useContext(CustomDomainContext);
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const path = `/${params.orgSlug}/${params.eventSlug}`;

  /**
   * Path apa pun di platform, absolut saat kita sedang di custom domain. Dipakai
   * untuk semua yang **wajib** mendarat di domain utama: pendaftaran tim (butuh
   * sesi, dan refresh cookie terikat `.floevent.id` sehingga tidak bisa ikut
   * pindah), halaman pesanan tiket, serta tautan "Didukung flo-event" — di
   * custom domain `/` adalah halaman event itu sendiri, jadi tautan relatif ke
   * beranda akan berputar kembali ke dirinya sendiri.
   */
  const platformUrl = (to: string) => (customDomain ? `${APP_URL}${to}` : to);

  return {
    /**
     * Awalan tautan dalam-event: "" di custom domain, `/{org}/{event}` di domain
     * utama. Selalu dipakai sebagai awalan (`${base}/tickets`) — kalau butuh
     * tautan ke halaman event itu sendiri, pakai `base || "/"`.
     */
    base: customDomain ? "" : path,
    /** Sedang dilayani di domain milik event ini sendiri. */
    onCustomDomain: Boolean(customDomain),
    platformUrl,
    /** Halaman event ini di domain utama, mis. untuk `/register`. */
    mainUrl: (suffix = "") => platformUrl(`${path}${suffix}`),
    /** Beranda platform, yang di custom domain bukan `/`. */
    homeUrl: platformUrl("/"),
  };
}
