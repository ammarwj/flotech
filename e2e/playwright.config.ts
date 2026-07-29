import { defineConfig, devices } from "@playwright/test";

/**
 * E2E for the PRD user flows (§5). The suite drives the real stack: Next.js on
 * :3000 (host, `bun run dev`) against the dockerized Laravel API on :8000.
 *
 * Neither server is started here on purpose. `web` runs on the host with
 * Turbopack and the API lives in docker compose; booting either from Playwright
 * would fight the developer's own running instances. `globalSetup` fails fast
 * with instructions when something is down.
 */
export const WEB_URL = process.env.WEB_URL ?? "http://localhost:3000";
export const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export default defineConfig({
  testDir: "./specs",
  globalSetup: "./fixtures/global-setup.ts",
  outputDir: "./test-results",

  // Specs build their own data (unique emails per run) and never touch each
  // other's, so they parallelise safely.
  //
  // Capped rather than unbounded: the dev API is `artisan serve`, which forks a
  // fixed pool of workers (PHP_CLI_SERVER_WORKERS in docker-compose.dev.yml).
  // More browsers than that pool just queues requests behind each other.
  fullyParallel: true,
  workers: process.env.CI ? 2 : 4,

  /**
   * `payment_gateway_enabled` is platform-wide with no per-org override — the
   * one piece of state a spec cannot isolate by owning its own data. Specs that
   * have to switch it off are tagged `@gateway-off` and skipped here, then run
   * on their own (`bun run test:gateway-off`, which pins --workers=1) so
   * nothing else is in flight while the rails are rerouted.
   */
  grepInvert: process.env.GATEWAY_OFF ? undefined : /@gateway-off/,
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },

  // Fail the run if a test was committed with .only.
  forbidOnly: !!process.env.CI,

  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : [["list"], ["html", { open: "never" }]],

  use: {
    baseURL: WEB_URL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
    // The app is Indonesian; keep date/number formatting deterministic.
    locale: "id-ID",
    timezoneId: "Asia/Jakarta",
  },

  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
});
