import type { Metadata } from "next";
import { headers } from "next/headers";

import { EventBaseProvider } from "@/lib/event-base";
import type { PublicEvent } from "@/types/api";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

/**
 * Deskripsi event untuk `og:description`/`twitter:description`: HTML-nya
 * (`RegistrationForm`/`HtmlSanitizer` di backend sudah membersihkan XSS, bukan
 * tag) dibuang jadi teks polos, lalu dipotong. Pratinjau link WhatsApp/Telegram
 * tidak merender HTML — mengirimnya mentah akan mencetak `<p>` literal.
 */
function plainTextExcerpt(html: string | null, max = 200): string | undefined {
  if (!html) return undefined;
  const text = html
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  if (!text) return undefined;
  return text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;
}

/**
 * Event ini diambil di server, HANYA untuk metadata — `page.tsx` tetap
 * client-fetch sendiri untuk kontennya (lihat docblock di sana soal kenapa
 * seluruhnya client component). Dua fetch yang sama, bukan satu dioper lewat
 * prop: hooks `useQuery` di halaman butuh query key yang sudah ada, dan
 * membongkarnya untuk berbagi data server adalah refactor yang lebih besar
 * daripada yang diminta fitur ini. Gagal diam-diam (`null`) — metadata yang
 * generic lebih baik daripada 500 di seluruh halaman event gara-gara API lagi
 * turun.
 */
async function fetchEventForMetadata(orgSlug: string, eventSlug: string): Promise<PublicEvent | null> {
  try {
    const res = await fetch(`${API_URL}/public/events/${orgSlug}/${eventSlug}`, {
      next: { revalidate: 60 },
    });
    if (!res.ok) return null;

    const { data } = await res.json();
    return (data as PublicEvent) ?? null;
  } catch {
    return null;
  }
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ orgSlug: string; eventSlug: string }>;
}): Promise<Metadata> {
  const { orgSlug, eventSlug } = await params;
  const ev = await fetchEventForMetadata(orgSlug, eventSlug);

  // Tanpa data (API turun, atau slug tidak ketemu — 404-nya tetap ditangani
  // page.tsx sendiri), warisi judul/deskripsi platform dari root layout alih-alih
  // menimpanya dengan sesuatu yang kosong.
  if (!ev) return {};

  // Event white-label tidak pernah menyebut flo-event di mana pun di
  // halamannya (tautan "Didukung flo-event", footer platform) — judul tab dan
  // pratinjau link ikut aturan yang sama, bukan pengecualian kedua.
  const title = ev.white_label ? ev.name : `${ev.name} — flo-event`;
  const description =
    plainTextExcerpt(ev.description) ??
    (ev.organization.name
      ? `Turnamen ${ev.sport?.name ?? ""} oleh ${ev.organization.name}.`.replace(/\s+/g, " ").trim()
      : undefined);

  return {
    title,
    description,
    openGraph: {
      title,
      description,
      images: ev.banner_url ? [{ url: ev.banner_url }] : undefined,
    },
    twitter: {
      card: ev.banner_url ? "summary_large_image" : "summary",
      title,
      description,
      images: ev.banner_url ? [ev.banner_url] : undefined,
    },
  };
}

/**
 * Memberi tahu halaman event ia sedang dilayani di host yang mana.
 *
 * Server component justru karena itu: `proxy.ts` menaruh jawabannya di
 * header request, dan hanya server yang bisa membacanya pada render pertama.
 * Menebaknya di klien dari `window.location` akan cocok — satu render terlambat,
 * setelah hydration mismatch.
 *
 * `headers()` membuat subtree ini dynamic; halaman-halamannya toh sudah
 * client-fetch seluruhnya, jadi tidak ada static rendering yang dikorbankan.
 */
export default async function EventLayout({ children }: { children: React.ReactNode }) {
  const customDomain = (await headers()).get("x-flo-custom-domain");

  return <EventBaseProvider customDomain={customDomain}>{children}</EventBaseProvider>;
}
