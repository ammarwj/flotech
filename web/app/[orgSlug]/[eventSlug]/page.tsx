"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { getPublicEvent } from "@/lib/api/events";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { Button } from "@/components/ui/button";
import { SPORT_LABELS, FORMAT_LABELS, EVENT_STATUS_LABELS, rupiah } from "@/lib/labels";

export default function PublicEventPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();

  const query = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
    retry: false,
  });

  if (query.isLoading) {
    return <div className="container" style={{ paddingBlock: 80 }}>Memuat…</div>;
  }

  if (query.isError || !query.data) {
    return (
      <div className="container" style={{ paddingBlock: 80, textAlign: "center" }}>
        <h1 className="section-title">Event tidak ditemukan</h1>
        <p className="section-sub">Periksa kembali tautannya.</p>
        <Button asChild className="mt-6">
          <Link href="/">Ke beranda</Link>
        </Button>
      </div>
    );
  }

  const ev = query.data;
  const base = `/${params.orgSlug}/${params.eventSlug}`;

  return (
    <>
      <header
        className="ehero"
        style={{
          background:
            "linear-gradient(180deg, rgba(7,11,22,0.30), rgba(7,11,22,0.86) 70%, var(--bg)), linear-gradient(125deg, #0B2C6B, #1E6FFF 48%, #7C3AED)",
        }}
      >
        <div className="container ehero-inner">
          <div className="ehero-top">
            <Link href="/" className="ehero-back">
              Didukung flo-event
            </Link>
            <ThemeToggleButton />
          </div>
          <div className="ehero-grid">
            <div>
              <div className="ehero-badges">
                <span className="ehero-badge sport">{SPORT_LABELS[ev.sport_type]}</span>
                <span className="ehero-badge">{FORMAT_LABELS[ev.tournament_format]}</span>
                <span className="ehero-badge">{EVENT_STATUS_LABELS[ev.status]}</span>
              </div>
              <h1>{ev.name}</h1>
              <div className="ehero-info">
                <span>
                  {ev.start_date} – {ev.end_date}
                </span>
                {ev.location_name && <span>{ev.location_name}</span>}
                <span>{ev.approved_teams_count} tim disetujui</span>
              </div>
              <div className="ehero-cta">
                {ev.registration_is_open ? (
                  <Button asChild size="lg" style={{ background: "#fff", color: "var(--brand-700)" }}>
                    <Link href={`${base}/register`}>Daftar Tim Sekarang</Link>
                  </Button>
                ) : (
                  <span className="ehero-badge">Pendaftaran ditutup</span>
                )}
              </div>
            </div>
          </div>
        </div>
      </header>

      <section className="section">
        <div className="container" style={{ maxWidth: 760 }}>
          <h2 className="section-title">Tentang Event</h2>
          <p className="section-sub" style={{ whiteSpace: "pre-line" }}>
            {ev.description || "Belum ada deskripsi."}
          </p>

          <div className="mt-8 grid gap-4 sm:grid-cols-3">
            <Info label="Biaya Registrasi" value={ev.registration_fee > 0 ? rupiah(ev.registration_fee) : "Gratis"} />
            <Info label="Kuota Tim" value={ev.max_teams ? `${ev.max_teams} tim` : "Tidak dibatasi"} />
            <Info label="Penyelenggara" value={ev.organization.name ?? "-"} />
          </div>
        </div>
      </section>

      <footer className="footer">
        <div className="container">
          <div className="footer-bottom" style={{ marginTop: 0, paddingTop: 0, borderTop: "none" }}>
            <Link href="/" className="logo">
              flo<span>-event</span>
            </Link>
            <span>Halaman event ini dibuat dengan flo-event · © 2026</span>
          </div>
        </div>
      </footer>
    </>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <div className="text-sm text-muted-foreground">{label}</div>
      <div className="mt-1 font-bold" style={{ fontFamily: "var(--font-display)" }}>
        {value}
      </div>
    </div>
  );
}
