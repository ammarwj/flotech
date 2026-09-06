import { expect, test, beforeAll } from "bun:test";
import { NextRequest } from "next/server";

/**
 * `bun test proxy.test.ts` — runner bawaan bun, tanpa dependensi baru.
 *
 * Yang diuji cuma keputusan routing-nya, karena di situlah bug-nya bersembunyi:
 * satu cabang yang salah mengirim pengunjung ke domain yang salah, atau — lebih
 * buruk — mengalihkan `/register` ke host yang tidak akan pernah menerima
 * cookie sesinya.
 */

const DOMAINS = {
  "eventa.id": { org_slug: "jkt", event_slug: "cup" },
};

beforeAll(() => {
  process.env.NEXT_PUBLIC_APP_URL = "https://floevent.id";
  globalThis.fetch = (async () =>
    new Response(JSON.stringify({ data: { domains: DOMAINS } }), {
      headers: { "content-type": "application/json" },
    })) as unknown as typeof fetch;
});

const { proxy } = await import("./proxy");

// `new Request()` tidak mengisi header Host sendiri, sementara request sungguhan
// selalu membawanya — jadi di sini ia dieja.
const go = (url: string) =>
  proxy(
    new NextRequest(new Request(url, { headers: { host: new URL(url).host } })),
  );

test("root custom domain di-rewrite ke halaman eventnya", async () => {
  const res = await go("https://eventa.id/");

  expect(res.headers.get("x-middleware-rewrite")).toBe("https://eventa.id/jkt/cup");
});

test("/tickets ikut, /tickets/{id} tidak", async () => {
  // Dibandingkan, bukan diperiksa satu-satu: beli tiket tidak butuh login jadi
  // ia ikut, sementara halaman pesanan terikat sesi jadi ia pulang ke platform.
  const shop = await go("https://eventa.id/tickets");
  const order = await go("https://eventa.id/tickets/abc-123");

  expect(shop.headers.get("x-middleware-rewrite")).toBe("https://eventa.id/jkt/cup/tickets");
  expect(order.status).toBe(301);
  expect(order.headers.get("location")).toBe("https://floevent.id/tickets/abc-123");
});

test("halaman event lama 301 ke custom domain — tapi /register tidak pernah", async () => {
  // Perbandingan inilah intinya. Assert redirect-nya saja akan tetap hijau walau
  // /register ikut terseret, dan itu berarti loop login: refresh cookie terikat
  // .floevent.id sehingga tidak bisa menyeberang.
  const page = await go("https://floevent.id/jkt/cup");
  const register = await go("https://floevent.id/jkt/cup/register");

  expect(page.status).toBe(301);
  expect(page.headers.get("location")).toBe("https://eventa.id/");
  expect(register.status).toBe(200);
  expect(register.headers.get("location")).toBeNull();
});

test("event tanpa custom domain tetap dilayani di domain utama", async () => {
  const res = await go("https://floevent.id/jkt/lain");

  expect(res.status).toBe(200);
  expect(res.headers.get("x-middleware-rewrite")).toBeNull();
});

test("header custom domain tidak bisa dipalsukan lewat request", async () => {
  // Klien mengirimnya sendiri; layout membaca `headers()`, jadi kalau ia lolos,
  // siapa pun bisa membuat halaman di domain utama menyembunyikan widget akun.
  const res = await proxy(
    new NextRequest(
      new Request("https://floevent.id/jkt/lain", {
        headers: { host: "floevent.id", "x-flo-custom-domain": "eventa.id" },
      }),
    ),
  );

  expect(res.headers.get("x-middleware-request-x-flo-custom-domain")).toBeNull();
});
