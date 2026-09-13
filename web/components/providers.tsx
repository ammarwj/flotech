"use client";

import { useState } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { ConfirmProvider } from "@/components/shared/confirm-provider";
import { Toaster } from "@/components/ui/sonner";
import { SITE_SETTINGS_KEY } from "@/lib/hooks/use-site-settings";
import type { SiteSettings } from "@/types/api";

export function Providers({
  children,
  siteSettings,
}: {
  children: React.ReactNode;
  /**
   * Fetched in the root layout. Seeded into the cache here so the first client
   * render already has the platform logo — otherwise every page load paints the
   * built-in mark and swaps it a moment later.
   *
   * Null when the API could not be reached; the cache stays empty and
   * `useSiteSettings()` fetches as usual.
   */
  siteSettings?: SiteSettings | null;
}) {
  const [queryClient] = useState(() => {
    const client = new QueryClient({
      defaultOptions: {
        queries: {
          staleTime: 60 * 1000,
          retry: 1,
          refetchOnWindowFocus: false,
        },
      },
    });

    // Inside the initializer, not an effect: an effect runs after the first
    // paint, which is exactly the frame the flash happens in.
    if (siteSettings) {
      client.setQueryData(SITE_SETTINGS_KEY, siteSettings);
    }

    return client;
  });

  return (
    <QueryClientProvider client={queryClient}>
      <ConfirmProvider>{children}</ConfirmProvider>
      <Toaster />
    </QueryClientProvider>
  );
}
