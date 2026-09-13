import Link from "next/link";
import { Compass, Home, SearchX } from "lucide-react";

import { Logo } from "@/components/shared/logo";
import { Button } from "@/components/ui/button";

/**
 * The site-wide 404.
 *
 * Renders for any unmatched route *and* for `notFound()` — which the public
 * organizer profile calls on an unknown slug, the most likely way a visitor
 * lands here: a mistyped or long-dead event link.
 *
 * So the two suggestions are the two things such a visitor can actually do —
 * browse the events that do exist, or start over from the homepage. A bare
 * "back" would send them to whatever produced the broken link.
 */
export default function NotFound() {
  return (
    <div className="grid min-h-screen place-items-center bg-[var(--bg-alt)] px-4 py-16">
      <div className="w-full max-w-md text-center">
        <Logo className="logo justify-center" />

        <div className="mt-10 grid place-items-center">
          <div className="grid h-16 w-16 place-items-center rounded-2xl bg-[var(--tint)] text-[var(--brand-600)]">
            <SearchX className="h-8 w-8" />
          </div>
        </div>

        <p
          className="mt-6 text-5xl font-extrabold tracking-tight text-[var(--brand-600)]"
          style={{ fontFamily: "var(--font-display)" }}
        >
          404
        </p>

        <h1 className="mt-3 text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Halaman tidak ditemukan
        </h1>

        <p className="mt-2 text-sm text-muted-foreground">
          Tautannya mungkin salah ketik, atau halamannya sudah tidak ada. Event yang
          selesai kadang ditutup penyelenggaranya.
        </p>

        <div className="mt-7 flex flex-wrap justify-center gap-3">
          <Button asChild>
            <Link href="/event">
              <Compass className="h-4 w-4" />
              Jelajahi event
            </Link>
          </Button>
          <Button asChild variant="outline">
            <Link href="/">
              <Home className="h-4 w-4" />
              Ke beranda
            </Link>
          </Button>
        </div>
      </div>
    </div>
  );
}
