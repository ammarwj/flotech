"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, CalendarDays, MapPin, Users, Wallet, Trophy, Building2, Ticket, Info, Network, CalendarClock, ListOrdered, Goal } from "lucide-react";

import { getPublicEvent } from "@/lib/api/events";
import { PublicAuthActions } from "@/components/auth/public-auth-actions";
import { PublicResults, type ResultsTab } from "@/components/event/public-results";
import { PhotoGallery, SponsorStrip } from "@/components/event/public-media";
import { TeamRosterDialog } from "@/components/event/team-roster-dialog";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { EVENT_STATUS_LABELS, rupiah } from "@/lib/labels";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { isKnockout as isKnockoutFormat, isHybrid as isHybridFormat, crestGradient } from "@/lib/bracket";
import { cn } from "@/lib/utils";
import type { PublicTeam } from "@/types/api";
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

type TabKey = "info" | "teams" | ResultsTab;

export default function PublicEventPage() {
  const { sportLabel, sportColor: colorOf, formatLabel } = useCatalog();
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const base = `/${params.orgSlug}/${params.eventSlug}`;
  const [tab, setTab] = useState<TabKey>("info");
  const [openTeam, setOpenTeam] = useState<PublicTeam | null>(null);

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
  const sportColor = colorOf(ev.sport_type);

  // Top-level tabs: Info + the format-appropriate match panels. A hybrid event
  // has both a group table and a bracket.
  const tabs: [TabKey, string, typeof Info][] = [
    ["info", "Info", Info],
    ["teams", "Tim Peserta", Users],
    ["schedule", "Jadwal", CalendarClock],
    ...(isKnockoutFormat(ev.engine)
      ? ([["bracket", "Bracket", Network]] as [TabKey, string, typeof Info][])
      : isHybridFormat(ev.engine)
        ? ([
            ["standings", "Klasemen", ListOrdered],
            ["bracket", "Bracket", Network],
          ] as [TabKey, string, typeof Info][])
        : ([["standings", "Klasemen", ListOrdered]] as [TabKey, string, typeof Info][])),
    ["stats", "Statistik", Goal],
  ];

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
            <div className="flex items-center gap-2">
              <PublicAuthActions />
              <ThemeToggleButton />
            </div>
          </div>

          <div className="ehero-grid">
            <div>
              <div className="ehero-badges">
                <span className="ehero-badge sport" style={{ color: sportColor }}>
                  {sportLabel(ev.sport_type)}
                </span>
                <span className="ehero-badge">{formatLabel(ev.tournament_format)}</span>
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
                {ev.tickets_on_sale && (
                  <Link href={`${base}/tickets`} className="btn btn-secondary btn-lg">
                    Beli Tiket
                  </Link>
                )}
              </div>
            </div>
          </div>
        </div>
      </header>

      {/* ===== TABS ===== */}
      <section className="section" style={{ paddingBottom: 0 }}>
        <div className="container">
          <div className="inline-flex flex-wrap items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
            {tabs.map(([key, label, Icon]) => (
              <button
                key={key}
                onClick={() => setTab(key)}
                className={cn(
                  "inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 transition-colors",
                  tab === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
                )}
              >
                <Icon className="h-4 w-4" />
                {label}
              </button>
            ))}
          </div>
        </div>
      </section>

      {/* ===== INFO TAB ===== */}
      {tab === "info" && (
        <>
          {ev.banner_url && (
            <section className="section" style={{ paddingBottom: 0 }}>
              <div className="container">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={ev.banner_url}
                  alt={ev.name}
                  className="mx-auto block w-full rounded-2xl border border-border object-cover"
                  style={{ aspectRatio: "4 / 5", maxWidth: 420 }}
                />
              </div>
            </section>
          )}

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

                {ev.photos && <PhotoGallery photos={ev.photos} />}
                {ev.sponsors && <SponsorStrip sponsors={ev.sponsors} />}
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
                      {ev.organization.slug ? (
                        <Link href={`/${ev.organization.slug}`}>{ev.organization.name ?? "-"}</Link>
                      ) : (
                        (ev.organization.name ?? "-")
                      )}
                    </span>
                  </div>
                  <div className="price-tier">
                    <div>
                      <b>Cabang</b>
                      <small>Olahraga</small>
                    </div>
                    <span className="amt" style={{ fontSize: 14, color: sportColor }}>
                      {sportLabel(ev.sport_type)}
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
                      <b>Kuota</b>
                      <small>Tim</small>
                    </div>
                    <span className="amt" style={{ fontSize: 14 }}>
                      {ev.approved_teams_count} / {ev.max_teams ?? "∞"}
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

                {ev.tickets_on_sale && (
                  <div className="card scard">
                    <h3>
                      <Ticket />
                      Tiket Penonton
                    </h3>
                    <p style={{ fontSize: 14, color: "var(--text-muted)", marginBottom: 16 }}>
                      Tiket digital tersedia. Beli sekarang dan masuk dengan QR Code.
                    </p>
                    <Link href={`${base}/tickets`} className="btn btn-primary btn-block">
                      <Ticket className="h-4 w-4" />
                      Beli Tiket
                    </Link>
                  </div>
                )}
              </aside>
            </div>
          </section>
        </>
      )}

      {/* ===== TEAMS TAB ===== */}
      {tab === "teams" && (
        <section className="section">
          <div className="container">
            <div className="esection-title">
              <h2 className="section-title" style={{ margin: 0 }}>
                Tim Peserta
              </h2>
              {ev.approved_teams && ev.approved_teams.length > 0 && (
                <span className="pill">{ev.approved_teams.length} tim</span>
              )}
            </div>

            {ev.approved_teams && ev.approved_teams.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {ev.approved_teams.map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    onClick={() => setOpenTeam(t)}
                    className="match-card text-left transition-colors hover:border-[var(--brand-600)]"
                    style={{ gridTemplateColumns: "auto 1fr auto" }}
                  >
                    {t.logo_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={t.logo_url}
                        alt={t.name}
                        className="object-cover"
                        style={{ width: 40, height: 40, borderRadius: 10, border: "1px solid var(--border)" }}
                      />
                    ) : (
                      <span className="crest" style={{ width: 40, height: 40, borderRadius: 10, background: crestGradient(t.name) }} />
                    )}
                    <div className="min-w-0">
                      <div className="match-team" style={{ gap: 0 }}>
                        <span className="truncate">{t.name}</span>
                      </div>
                    </div>
                    <span className="pill shrink-0">{t.players?.length ?? 0} pemain</span>
                  </button>
                ))}
              </div>
            ) : (
              <p className="section-sub" style={{ margin: 0 }}>
                Belum ada tim yang disetujui untuk event ini.
              </p>
            )}
          </div>
        </section>
      )}

      {openTeam && (
        <TeamRosterDialog
          team={openTeam}
          sport={ev.sport_type}
          onClose={() => setOpenTeam(null)}
        />
      )}

      {/* ===== SCHEDULE / BRACKET / STANDINGS / STATS ===== */}
      {tab !== "info" && tab !== "teams" && (
        <PublicResults
          orgSlug={params.orgSlug}
          eventSlug={params.eventSlug}
          engine={ev.engine}
          bracketConfig={ev.bracket_config}
          activeTab={tab}
        />
      )}

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
