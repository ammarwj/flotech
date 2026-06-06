import Link from "next/link";

const NAV = [
  { href: "/dashboard", label: "Ringkasan" },
  { href: "/dashboard/events", label: "Event" },
  { href: "/dashboard/my-teams", label: "Tim Saya" },
  { href: "/dashboard/schedule", label: "Jadwal" },
  { href: "/dashboard/standings", label: "Klasemen" },
  { href: "/dashboard/tickets", label: "Tiket" },
  { href: "/dashboard/certificates", label: "Sertifikat" },
  { href: "/dashboard/settings", label: "Pengaturan" },
];

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen grid grid-cols-1 md:grid-cols-[240px_1fr]">
      {/* Sidebar */}
      <aside className="hidden md:flex flex-col border-r border-border bg-[var(--bg-alt)] p-4">
        <Link href="/" className="logo mb-6">
          <span className="logo-mark">
            <svg viewBox="0 0 24 24" fill="none">
              <path
                d="M5 4h14l-2 6H7l1 10"
                stroke="#fff"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
              <circle cx="9" cy="12" r="1.4" fill="#fff" />
            </svg>
          </span>
          flo<span>-event</span>
        </Link>
        <nav className="flex flex-col gap-1">
          {NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className="rounded-md px-3 py-2 text-sm font-medium text-[var(--text-2)] hover:bg-accent hover:text-accent-foreground transition-colors"
            >
              {item.label}
            </Link>
          ))}
        </nav>
      </aside>

      {/* Content */}
      <div className="flex flex-col">
        <header className="h-16 border-b border-border flex items-center px-6 bg-[var(--surface)]">
          <span className="text-sm text-muted-foreground">Dashboard Organizer</span>
        </header>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
