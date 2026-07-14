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
    data: { email: "admin@flo-event.id", password: "password" },
  });
  if (!login.ok()) {
    throw new Error(
      "Akun seeder admin@flo-event.id tidak bisa login.\n" +
        "Jalankan: docker compose exec api php artisan db:seed",
    );
  }

  await ctx.dispose();
}
