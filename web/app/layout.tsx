import type { Metadata } from "next";
import { Outfit, Inter, JetBrains_Mono } from "next/font/google";
import { Providers } from "@/components/providers";
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
 * Picks up the favicon uploaded at /admin/site-settings.
 *
 * Revalidated rather than fetched per request: this is the root layout of every
 * page on the site, and branding changes about never. Five minutes is the delay
 * an admin waits after uploading, and the price is one request per window
 * instead of one per visitor.
 *
 * Failures are swallowed on purpose — an API that is down must not take the
 * whole site with it over an icon.
 */
export async function generateMetadata(): Promise<Metadata> {
  const withIcon = (icon: string): Metadata => ({ ...BASE_METADATA, icons: { icon } });

  try {
    const res = await fetch(`${API_URL}/site-settings`, { next: { revalidate: 300 } });
    if (!res.ok) return withIcon(DEFAULT_ICON);

    const { data } = await res.json();
    return withIcon(data?.favicon_url || DEFAULT_ICON);
  } catch {
    return withIcon(DEFAULT_ICON);
  }
}

// Applies the persisted theme before paint to avoid a flash of the wrong theme.
const themeScript = `(function(){try{var t=localStorage.getItem('flo-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();`;

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
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
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
