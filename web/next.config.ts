import type { NextConfig } from "next";
import { withSentryConfig } from "@sentry/nextjs";

const nextConfig: NextConfig = {
  // Lean, self-contained server bundle for Docker (copied into the runner image).
  output: "standalone",
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
