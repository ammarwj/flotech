"use client";

import Link from "next/link";
import { useSiteSettings } from "@/lib/hooks/use-site-settings";
import { LogoMark } from "@/components/landing/icons";

/**
 * The platform wordmark — the one place that knows what the logo looks like.
 *
 * The markup used to be copy-pasted into the landing nav and footer as well.
 * Teaching three copies to load an uploaded logo would have been three chances
 * to drift, so they render this instead.
 *
 * The uploaded logo is optional, and the built-in mark is not a placeholder for
 * it: this renders in the dashboard sidebar before any query has resolved, and
 * a logo that blinks in from empty is worse than one that was always there.
 *
 * Shares `useSiteSettings()` with the footer, so rendering it on several
 * surfaces at once costs one request, not several — and one cache window,
 * rather than one per caller.
 */
export function Logo({
  href = "/",
  className,
  style,
  ariaLabel,
}: {
  href?: string;
  className?: string;
  style?: React.CSSProperties;
  ariaLabel?: string;
}) {
  const { data } = useSiteSettings();

  const logo = data?.logo_url;

  return (
    <Link
      href={href}
      className={className ?? "logo"}
      style={style}
      aria-label={ariaLabel}
    >
      {logo ? (
        /* eslint-disable-next-line @next/next/no-img-element -- uploaded to R2
           or the local disk, so the host is not known at build time. */
        <img src={logo} alt="flo-event" className="logo-image" />
      ) : (
        <>
          <span className="logo-mark">
            <LogoMark />
          </span>
          flo<span>-event</span>
        </>
      )}
    </Link>
  );
}
