import { NextResponse, type NextRequest } from "next/server";

/**
 * Routing berdasarkan host untuk custom domain.
 *
 * Namanya `proxy.ts`, bukan `middleware.ts`: Next 16 mendeprekasi nama yang lama
 * (build memperingatkannya). Runtime `proxy` selalu nodejs dan tidak bisa
 * diubah — kebetulan justru yang dibutuhkan di sini, karena ia harus memanggil
 * `http://api:8000` di dalam jaringan Docker.
 *
 * Sebuah event bisa disajikan di hostname-nya sendiri (`eventa.id`) alih-alih di
 * `floevent.id/{org}/{event}`. Penulisan ulangnya terjadi di sini, bukan di pohon
 * route, karena path yang diketik pengunjung di custom domain (`/`, `/tickets`)
 * sama sekali tidak membawa slug — tidak ada yang bisa dicocokkan segmen route.
 *
 * `rewrite` tidak mengubah `useParams()`, jadi setiap halaman di bawah tetap
 * membaca slug yang sama seperti biasa dan tidak ada fetching yang berubah.
 *
 * Dua hal sengaja tetap tinggal di domain utama:
 *
 *  - **Pendaftaran tim.** Ia butuh sesi, dan refresh cookie terikat
 *    `.floevent.id` (`AuthController::makeRefreshCookie`) sehingga browser
 *    secara struktural tidak bisa mengirimkannya ke domain yang dikendalikan
 *    organizer. Mengalihkan `/register` ke custom domain = loop login.
 *  - **Sisa platform** (`/pricing`, `/organizer`, …). Custom domain adalah situs
 *    satu event, bukan salinan kedua flo-event.
 */

/** Tujuan path non-event di custom domain, sekaligus rumah `/register`. */
const APP_URL = (process.env.NEXT_PUBLIC_APP_URL ?? "https://floevent.id").replace(/\/$/, "");

/**
 * Dibaca di server, jadi bisa memakai nama container di dalam jaringan Docker
 * dan tidak perlu memutar lewat internet publik. Jatuh ke URL publik, yang
 * memang yang dipunyai mesin `bun run dev`.
 */
const API_URL = (
  process.env.INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://localhost:8000/api/v1"
).replace(/\/$/, "");

/**
 * Header yang memberi tahu pohon halaman bahwa ia sedang dilayani di custom
 * domain — isinya hostname-nya. Sengaja bukan "base path kosong": string kosong
 * tidak bisa dibedakan dari header yang tidak ada.
 *
 * Selalu ditulis ulang di sini (dihapus di domain utama) supaya klien tidak bisa
 * memalsukannya lewat header request.
 */
const HOST_HEADER = "x-flo-custom-domain";

type DomainMap = Record<string, { org_slug: string; event_slug: string }>;

/**
 * Di-cache di module scope: `output: "standalone"` adalah satu proses node, jadi
 * ini bertahan antar-request. TTL pendek itulah yang membuat domain yang baru
 * diaktifkan mulai bekerja dalam semenit tanpa deploy ulang.
 */
const TTL_MS = 60_000;
let cache: DomainMap = {};
let fetchedAt = 0;
let inflight: Promise<DomainMap> | null = null;

async function domains(): Promise<DomainMap> {
  if (Date.now() - fetchedAt < TTL_MS) return cache;
  // Satu request saja walau beberapa page load mendarat di tick yang sama.
  if (inflight) return inflight;

  inflight = fetch(`${API_URL}/public/domains`, { cache: "no-store" })
    .then((res) => (res.ok ? res.json() : null))
    .then((body) => {
      const next = body?.data?.domains;
      if (next && typeof next === "object") {
        cache = next as DomainMap;
        fetchedAt = Date.now();
      }
      return cache;
    })
    // Tetap sajikan peta terakhir yang baik saat API tersendat: satu blip tidak
    // boleh mematikan semua custom domain sekaligus. Peta kosong hanya berarti
    // "aturan domain utama yang berlaku".
    .catch(() => cache)
    .finally(() => {
      inflight = null;
    });

  return inflight;
}

export async function proxy(request: NextRequest) {
  const host = (request.headers.get("host") ?? "").toLowerCase().split(":")[0];
  const path = request.nextUrl.pathname;
  const map = await domains();
  const match = map[host];

  const headers = new Headers(request.headers);

  if (match) {
    const base = `/${match.org_slug}/${match.event_slug}`;

    // Halaman event dan toko tiket adalah keseluruhan sebuah custom domain.
    // Beli tiket tidak butuh login (hanya nama/email/telepon), itu sebabnya ia
    // bisa ikut sementara pendaftaran tim tidak.
    if (path === "/" || path === "/tickets") {
      const url = request.nextUrl.clone();
      url.pathname = path === "/" ? base : `${base}/tickets`;

      headers.set(HOST_HEADER, host);
      // Header dipasang di `request`, bukan di response: `headers()` di server
      // component hanya melihat header request.
      return NextResponse.rewrite(url, { request: { headers } });
    }

    // Sisanya — /pricing, /organizer, dan /tickets/{orderId} yang menampilkan
    // pesanan milik satu sesi — punya platform, bukan punya event ini.
    return NextResponse.redirect(`${APP_URL}${path}${request.nextUrl.search}`, 301);
  }

  headers.delete(HOST_HEADER);

  // Di domain utama, event yang punya domain aktif disajikan dari sana.
  // `/register` tidak pernah dialihkan: di sanalah sesinya hidup.
  const segments = path.split("/").filter(Boolean);
  if (segments.length === 2 || (segments.length === 3 && segments[2] === "tickets")) {
    const [orgSlug, eventSlug] = segments;
    const domain = Object.keys(map).find(
      (name) => map[name].org_slug === orgSlug && map[name].event_slug === eventSlug,
    );

    if (domain) {
      const suffix = segments.length === 3 ? "/tickets" : "/";
      return NextResponse.redirect(`https://${domain}${suffix}${request.nextUrl.search}`, 301);
    }
  }

  return NextResponse.next({ request: { headers } });
}

export const config = {
  /**
   * Semuanya kecuali aset milik Next, route handler `/api/*` (ada
   * `app/api/auth/refresh`), dan file berekstensi. Perhatikan proxy ini
   * juga jalan di `/` — itulah yang membuat root sebuah custom domain menyajikan
   * eventnya alih-alih halaman landing.
   */
  matcher: ["/((?!_next/|api/|favicon.ico|.*\\.[^/]+$).*)"],
};
