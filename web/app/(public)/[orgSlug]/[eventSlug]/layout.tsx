import { headers } from "next/headers";

import { EventBaseProvider } from "@/lib/event-base";

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
