import type { Metadata } from "next";

/**
 * The index page is a client component (it drives search and filters from the
 * URL), and a client component cannot export `metadata` — hence this layout.
 *
 * It only covers `/blog`: an article's own generateMetadata runs deeper in the
 * tree and overrides every field below.
 */
export const metadata: Metadata = {
  title: "Blog — flo-event",
  description:
    "Panduan menyelenggarakan turnamen, praktik baik kompetisi olahraga, dan kabar fitur terbaru flo-event.",
};

export default function BlogLayout({ children }: { children: React.ReactNode }) {
  return children;
}
