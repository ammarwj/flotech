import Link from "next/link";
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
      { href: "#", label: "Kontak" },
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
          </div>
          {COLUMNS.map((col) => (
            <div key={col.title}>
              <h4>{col.title}</h4>
              <div className="footer-links">
                {col.links.map((l, i) => (
                  <Link key={`${l.label}-${i}`} href={l.href}>
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
