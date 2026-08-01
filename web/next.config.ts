import type { NextConfig } from "next";
import { withSentryConfig } from "@sentry/nextjs";

const nextConfig: NextConfig = {
  // Lean, self-contained server bundle for Docker (copied into the runner image).
  output: "standalone",

  /**
   * Plans are bought per event now, so "upgrade" and "subscription" stopped
   * describing anything. The old URLs are in bookmarks, in the Midtrans return
   * URL of any checkout opened before this deploy, and in already-sent emails —
   * so they redirect rather than 404.
   */
  async redirects() {
    return [
      { source: "/organizer/upgrade", destination: "/organizer/plans", permanent: true },
      { source: "/organizer/subscription", destination: "/organizer/billing", permanent: true },
      { source: "/admin/subscriptions", destination: "/admin/plan-orders", permanent: true },
    ];
  },
};

// Only enable the Sentry build plugin when a DSN is configured, so local/CI
// builds without Sentry credentials stay clean.
const config: NextConfig = process.env.NEXT_PUBLIC_SENTRY_DSN
  ? withSentryConfig(nextConfig, {
      org: process.env.SENTRY_ORG,
      project: process.env.SENTRY_PROJECT,
      authToken: process.env.SENTRY_AUTH_TOKEN,
      silent: true,
      telemetry: false,
    })
  : nextConfig;

export default config;
