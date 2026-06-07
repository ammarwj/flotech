"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, CalendarDays, MapPin, Users, Wallet, Trophy, Building2 } from "lucide-react";

import { getPublicEvent } from "@/lib/api/events";
import { PublicResults } from "@/components/event/public-results";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { SPORT_LABELS, FORMAT_LABELS, EVENT_STATUS_LABELS, SPORT_COLORS, rupiah } from "@/lib/labels";
import "../../event-shell.css";

function fmtDate(d: string | null) {
  if (!d) return "";
  return new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
}

function dateRange(a: string | null, b: string | null) {
  if (!a && !b) return "Tanggal menyusul";
  if (a && b) return `${fmtDate(a)} – ${fmtDate(b)}`;
  return fmtDate(a ?? b);
}

const CREST_GRADIENTS = [
  "linear-gradient(135deg,#1E6FFF,#1558CC)",
  "linear-gradient(135deg,#059669,#047857)",
  "linear-gradient(135deg,#DC2626,#991B1B)",
  "linear-gradient(135deg,#D97706,#B45309)",
  "linear-gradient(135deg,#7C3AED,#5B21B6)",
  "linear-gradient(135deg,#0EA5E9,#0369A1)",
];

function crest(seed: string) {
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  return CREST_GRADIENTS[h % CREST_GRADIENTS.length];
}

export default function PublicEventPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const base = `/${params.orgSlug}/${params.eventSlug}`;

  const query = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
    retry: false,
  });

  if (query.isLoading) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center", color: "var(--text-muted)" }}>
        Memuat…
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center" }}>
        <h1 className="section-title">Event tidak ditemukan</h1>
        <p className="section-sub">Periksa kembali tautannya atau event belum dipublikasikan.</p>
        <Link href="/" className="btn btn-primary btn-lg" style={{ marginTop: 24 }}>
          Ke beranda
        </Link>
      </div>
    );
  }

  const ev = query.data;
  const sportColor = SPORT_COLORS[ev.sport_type];

  return (
    <>
      {/* ===== HERO ===== */}
      <header className="ehero">
        <div className="container ehero-inner">
          <div className="ehero-top">
            <Link href="/" className="ehero-back">
              <ArrowLeft />
              Didukung flo-event
            </Link>
            <ThemeToggleButton />
          </div>

          <div className="ehero-grid">
            <div>
              <div className="ehero-badges">
                <span className="ehero-badge sport" style={{ color: sportColor }}>
                  {SPORT_LABELS[ev.sport_type]}
                </span>
                <span className="ehero-badge">{FORMAT_LABELS[ev.tournament_format]}</span>
                <span className="ehero-badge">
                  <span
                    style={{
                      width: 7,
                      height: 7,
                      borderRadius: "50%",
                      background: ev.registration_is_open ? "#22D3A7" : "rgba(255,255,255,0.6)",
                      display: "inline-block",
                    }}
                  />
                  {ev.registration_is_open ? "Pendaftaran Dibuka" : EVENT_STATUS_LABELS[ev.status]}
                </span>
              </div>

              <h1>{ev.name}</h1>

              <div className="ehero-info">
                <span>
                  <CalendarDays />
                  {dateRange(ev.start_date, ev.end_date)}
                </span>
                {ev.location_name && (
                  <span>
                    <MapPin />
                    {ev.location_name}
                  </span>
                )}
                <span>
                  <Users />
                  {ev.approved_teams_count} tim
                </span>
              </div>

              <div className="ehero-cta">
                {ev.registration_is_open ? (
                  <Link href={`${base}/register`} className="btn btn-primary btn-lg">
                    Daftar Tim Sekarang
                  </Link>
                ) : (
                  <span className="ehero-badge" style={{ height: 44, paddingInline: 20 }}>
                    Pendaftaran ditutup
                  </span>
                )}
              </div>
            </div>
          </div>
        </div>
      </header>

      {/* ===== STAT BAR ===== */}
      <section className="ebar">
        <div className="container ebar-grid">
          <div className="ebar-cell">
            <b>{ev.approved_teams_count}</b>
            <span>Tim disetujui</span>
          </div>
          <div className="ebar-cell">
            <b style={{ fontSize: 18, paddingTop: 4 }}>{FORMAT_LABELS[ev.tournament_format]}</b>
            <span>Format turnamen</span>
          </div>
          <div className="ebar-cell">
            <b>{ev.max_teams ?? "∞"}</b>
            <span>Kuota tim</span>
          </div>
          <div className="ebar-cell">
            <b style={{ fontSize: ev.registration_fee > 0 ? 22 : 26 }}>
              {ev.registration_fee > 0 ? rupiah(ev.registration_fee) : "Gratis"}
            </b>
            <span>Biaya registrasi</span>
          </div>
        </div>
      </section>

      {/* ===== CONTENT ===== */}
      <section className="section">
        <div className="container elayout">
          {/* main */}
          <div>
            <div className="esection-title">
              <h2 className="section-title" style={{ margin: 0 }}>
                Tentang Event
              </h2>
            </div>
            <p className="section-sub" style={{ whiteSpace: "pre-line", margin: 0 }}>
              {ev.description || "Belum ada deskripsi untuk event ini."}
            </p>

            {ev.approved_teams && ev.approved_teams.length > 0 && (
              <>
                <div className="esection-title" style={{ marginTop: 40 }}>
                  <h2 className="section-title" style={{ margin: 0 }}>
                    Tim Peserta
                  </h2>
                  <span className="pill">{ev.approved_teams.length} tim</span>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  {ev.approved_teams.map((t) => (
                    <div key={t.id} className="match-card" style={{ gridTemplateColumns: "auto 1fr" }}>
                      <span className="crest" style={{ width: 40, height: 40, borderRadius: 10, background: crest(t.name) }} />
                      <div className="min-w-0">
                        <div className="match-team" style={{ gap: 0 }}>
                          <span className="truncate">{t.name}</span>
                        </div>
                        {t.city && <small style={{ color: "var(--text-muted)", fontSize: 12.5 }}>{t.city}</small>}
                      </div>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>

          {/* sidebar */}
          <aside>
            <div className="card scard">
              <h3>
                <Building2 />
                Info Event
              </h3>
              <div className="price-tier">
                <div>
                  <b>Penyelenggara</b>
                  <small>Organizer</small>
                </div>
                <span className="amt" style={{ fontSize: 14 }}>
                  {ev.organization.name ?? "-"}
                </span>
              </div>
              <div className="price-tier">
                <div>
                  <b>Cabang</b>
                  <small>Olahraga</small>
                </div>
                <span className="amt" style={{ fontSize: 14, color: sportColor }}>
                  {SPORT_LABELS[ev.sport_type]}
                </span>
              </div>
              <div className="price-tier">
                <div>
                  <b>Lokasi</b>
                  <small>Venue</small>
                </div>
                <span className="amt" style={{ fontSize: 14 }}>
                  {ev.location_name ?? "TBA"}
                </span>
              </div>
              <div className="price-tier">
                <div>
                  <b>Biaya</b>
                  <small>Per tim</small>
                </div>
                <span className="amt">
                  {ev.registration_fee > 0 ? rupiah(ev.registration_fee) : "Gratis"}
                </span>
              </div>
            </div>

            <div className="card scard">
              <h3>
                <Wallet />
                Pendaftaran
              </h3>
              {ev.registration_is_open ? (
                <>
                  <p style={{ fontSize: 14, color: "var(--text-muted)", marginBottom: 16 }}>
                    Pendaftaran tim sedang dibuka. Amankan slot timmu sekarang.
                  </p>
                  <Link href={`${base}/register`} className="btn btn-primary btn-block">
                    <Trophy className="h-4 w-4" />
                    Daftar Tim
                  </Link>
                </>
              ) : (
                <p style={{ fontSize: 14, color: "var(--text-muted)", margin: 0 }}>
                  Pendaftaran untuk event ini sedang ditutup.
                </p>
              )}
            </div>
          </aside>
        </div>
      </section>

      {/* ===== SCHEDULE & STANDINGS ===== */}
      <PublicResults orgSlug={params.orgSlug} eventSlug={params.eventSlug} format={ev.tournament_format} />

      {/* ===== REGISTER CTA ===== */}
      {ev.registration_is_open && (
        <section className="section" style={{ paddingTop: 0 }}>
          <div className="container">
            <div className="ereg">
              <h2>Siap bertanding di {ev.name}?</h2>
              <p>Daftarkan timmu, lengkapi data pemain, dan ikuti keseruan turnamennya.</p>
              <div className="ehero-cta">
                <Link href={`${base}/register`} className="btn btn-primary btn-lg">
                  Daftar Tim Sekarang
                </Link>
              </div>
            </div>
          </div>
        </section>
      )}

      {/* ===== FOOTER ===== */}
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
