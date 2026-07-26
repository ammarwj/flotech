"use client";

import type { CSSProperties } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Mail, Phone } from "lucide-react";

import { SocialIcon } from "@/components/shared/social-icon";
import { getPublicSiteSettings } from "@/lib/api/landing";
import { filledSocialLinks } from "@/lib/social";
import { LogoMark } from "./icons";

const COLUMNS = [
  {
    title: "Produk",
    links: [
      // Absolute anchors: the footer also renders on the event catalog, where a
      // bare "#fitur" would go nowhere.
      { href: "/#fitur", label: "Fitur" },
      { href: "/#cabang", label: "Cabang Olahraga" },
      { href: "/#harga", label: "Harga" },
      { href: "/event", label: "Jelajahi Event" },
    ],
  },
  {
    title: "Perusahaan",
    links: [
      { href: "#", label: "Tentang Kami" },
      { href: "#", label: "Blog" },
      { href: "#", label: "Karier" },
      // Resolved to a mailto once super_admin fills in the contact email.
      { href: "#", label: "Kontak", contact: true },
    ],
  },
  {
    title: "Bantuan",
    links: [
      { href: "#", label: "Pusat Bantuan" },
      { href: "#", label: "Dokumentasi API" },
      { href: "/ketentuan", label: "Ketentuan Layanan" },
      { href: "/ketentuan", label: "Kebijakan Privasi" },
    ],
  },
];

export function Footer() {
  // Rendered on six public pages, so it stays prop-less and fetches its own
  // data — the callers include async server components that could not pass it.
  const { data } = useQuery({ queryKey: ["public-site-settings"], queryFn: getPublicSiteSettings });

  const socials = filledSocialLinks(data?.social_links);
  const email = data?.contact_email;
  const phone = data?.contact_phone;

  // No `.reveal` anywhere below: RevealInit only sweeps the DOM on `/`, so a
  // revealed footer would sit at opacity 0 forever on the other five pages.
  return (
    <footer className="footer">
      <div className="container">
        <div className="footer-grid">
          <div>
            <Link href="/" className="logo" style={{ marginBottom: 16 }}>
              <span className="logo-mark">
                <LogoMark />
              </span>
              flo<span>-event</span>
            </Link>
            <p style={{ color: "var(--text-muted)", fontSize: "14.5px", maxWidth: 300 }}>
              Atur Turnamen, Tanpa Batas. Platform SaaS manajemen event olahraga end-to-end untuk penyelenggara
              Indonesia.
            </p>
            {(email || phone) && (
              <div className="footer-contact">
                {email && (
                  <a href={`mailto:${email}`}>
                    <Mail aria-hidden />
                    {email}
                  </a>
                )}
                {phone && (
                  <a href={`tel:${phone.replace(/\s/g, "")}`}>
                    <Phone aria-hidden />
                    {phone}
                  </a>
                )}
              </div>
            )}
            {socials.length > 0 && (
              <div className="footer-social">
                {socials.map((s) => (
                  <a
                    key={s.key}
                    href={s.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={s.label}
                    title={s.label}
                    // Each button carries its platform's mark colour, applied on
                    // hover/focus so the resting row stays one quiet grey.
                    style={{ "--social": s.color } as CSSProperties}
                  >
                    <SocialIcon platform={s.key} />
                  </a>
                ))}
              </div>
            )}
          </div>
          {COLUMNS.map((col) => (
            <div key={col.title}>
              <h4>{col.title}</h4>
              <div className="footer-links">
                {col.links.map((l, i) => (
                  <Link key={`${l.label}-${i}`} href={l.contact && email ? `mailto:${email}` : l.href}>
                    {l.label}
                  </Link>
                ))}
              </div>
            </div>
          ))}
        </div>
        <div className="footer-bottom">
          <span>© 2026 flo-event. Seluruh hak cipta dilindungi.</span>
          <span className="mono">Dibuat untuk penyelenggara turnamen Indonesia 🇮🇩</span>
        </div>
      </div>
    </footer>
  );
}
