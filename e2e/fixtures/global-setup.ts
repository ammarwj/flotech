import { request } from "@playwright/test";

import { API_URL, WEB_URL } from "../playwright.config";

/**
 * Both servers are the developer's own, so we don't start or stop them — we just
 * refuse to run against a stack that isn't up. A suite that silently fails 40
 * assertions because :3000 is down wastes far more time than this check.
 */
export default async function globalSetup() {
  const ctx = await request.newContext();

  const api = await ctx.get(`${API_URL}/health`).catch(() => null);
  if (!api?.ok()) {
    throw new Error(
      `API tidak merespons di ${API_URL}.\n` +
        `Jalankan: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`,
    );
  }

  const web = await ctx.get(WEB_URL).catch(() => null);
  if (!web?.ok()) {
    throw new Error(`Web tidak merespons di ${WEB_URL}.\nJalankan: cd web && bun run dev`);
  }

  // The super-admin flows (§5.8) sign in as the seeded platform admin; without
  // the seeder the wallet suite would fail with a confusing 401.
  const login = await ctx.post(`${API_URL}/auth/login`, {
    data: { email: "admin@floevent.id", password: "password" },
  });
  if (!login.ok()) {
    throw new Error(
      "Akun seeder admin@floevent.id tidak bisa login.\n" +
        "Jalankan: docker compose exec api php artisan db:seed && " +
        "docker compose exec api php artisan db:seed --class=UserSeeder --force",
    );
  }

  // A crashed @gateway-off run leaves the switch down, and the next default run
  // then dies in fixture setup with "Checkout tidak menghasilkan order id
  // Midtrans" — every spec that buys a plan, which is nearly all of them. The
  // failure reads like a broken checkout, so say what it actually is here.
  const token = (await login.json()).data.access_token;
  const settings = await ctx.get(`${API_URL}/admin/settings`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const gateway = (await settings.json()).data?.settings?.find(
    (s: { key: string; value: unknown }) => s.key === "payment_gateway_enabled",
  );
  if (gateway && !gateway.value) {
    throw new Error(
      "payment_gateway_enabled mati di DB dev — sisa run @gateway-off yang crash.\n" +
        "Nyalakan lagi di /admin/settings, atau: docker compose exec api php artisan tinker " +
        "--execute=\"App\\Models\\PlatformSetting::where('key','payment_gateway_enabled')" +
        "->update(['value'=>'1']); App\\Services\\PlatformSettings::flush();\"",
    );
  }

  await ctx.dispose();
}
