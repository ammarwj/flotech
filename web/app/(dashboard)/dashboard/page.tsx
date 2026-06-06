export default function DashboardPage() {
  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Selamat datang 👋
      </h1>
      <p className="mt-2 text-muted-foreground">
        Dashboard organizer flo-event. Modul event, tim, jadwal, dan lainnya dibangun pada fase
        berikutnya.
      </p>

      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "Event Aktif", value: "0" },
          { label: "Tim Terdaftar", value: "0" },
          { label: "Tiket Terjual", value: "0" },
          { label: "Sertifikat", value: "0" },
        ].map((c) => (
          <div key={c.label} className="rounded-lg border border-border bg-card p-5">
            <div className="text-sm text-muted-foreground">{c.label}</div>
            <div
              className="mt-1 text-3xl font-extrabold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              {c.value}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
