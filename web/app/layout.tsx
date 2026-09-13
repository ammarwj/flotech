import type { Metadata } from "next";
import { Outfit, Inter, JetBrains_Mono } from "next/font/google";
import { Providers } from "@/components/providers";
import type { SiteSettings } from "@/types/api";
import "./globals.css";

const display = Outfit({
  subsets: ["latin"],
  weight: ["500", "600", "700", "800"],
  variable: "--font-display",
  display: "swap",
});

const body = Inter({
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-body",
  display: "swap",
});

const mono = JetBrains_Mono({
  subsets: ["latin"],
  weight: ["400", "500"],
  variable: "--font-mono",
  display: "swap",
});

const BASE_METADATA: Metadata = {
  title: "flo-event — Atur Turnamen, Tanpa Batas",
  description:
    "Platform SaaS manajemen event olahraga end-to-end. Registrasi, jadwal, klasemen, bracket, tiket QR, hingga sertifikat — dalam satu platform.",
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

/**
 * The built-in icon, served from public/ rather than app/.
 *
 * That location is load-bearing: a file at `app/favicon.ico` is a file-based
 * convention that *overrides* `metadata.icons` entirely, so an uploaded favicon
 * would never have rendered while it sat there. From public/ it is just a URL,
 * and the metadata below decides which one wins.
 */
const DEFAULT_ICON = "/favicon.ico";

/**
 * Public site settings, fetched on the server.
 *
 * Revalidated rather than fetched per request: this is the root layout of every
 * page on the site, and branding changes about never. Five minutes is the delay
 * an admin waits after uploading, and the price is one request per window
 * instead of one per visitor.
 *
 * Failures return null on purpose — an API that is down must not take the whole
 * site with it over a logo. Both callers below fall back to the built-in mark.
 */
async function fetchSiteSettings(): Promise<SiteSettings | null> {
  try {
    const res = await fetch(`${API_URL}/site-settings`, { next: { revalidate: 300 } });
    if (!res.ok) return null;

    const { data } = await res.json();
    return (data as SiteSettings) ?? null;
  } catch {
    return null;
  }
}

export async function generateMetadata(): Promise<Metadata> {
  const settings = await fetchSiteSettings();

  return { ...BASE_METADATA, icons: { icon: settings?.favicon_url || DEFAULT_ICON } };
}

// Applies the persisted theme before paint to avoid a flash of the wrong theme.
const themeScript = `(function(){try{var t=localStorage.getItem('flo-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();`;

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  // Handed to react-query as initial data, so the very first client render
  // already knows the logo. Without it the built-in mark paints first and is
  // replaced a moment later — a visible flash on every page load, worst on the
  // login screen where the logo is the only thing on the page. The fetch above
  // is deduped with generateMetadata's by Next, so this costs no extra request.
  const settings = await fetchSiteSettings();

  return (
    <html
      lang="id"
      data-theme="light"
      data-scroll-behavior="smooth"
      className={`${display.variable} ${body.variable} ${mono.variable}`}
      suppressHydrationWarning
    >
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body suppressHydrationWarning>
        <Providers siteSettings={settings}>{children}</Providers>
      </body>
    </html>
  );
}
