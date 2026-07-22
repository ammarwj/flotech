"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, CalendarDays, MapPin, Users, Wallet, Trophy, Building2, Ticket, Info, Network, CalendarClock, ListOrdered, Goal, Search, X } from "lucide-react";

import { getPublicEvent } from "@/lib/api/events";
import { PublicAuthActions } from "@/components/auth/public-auth-actions";
import { PublicResults, type ResultsTab } from "@/components/event/public-results";
import { PublicAllSchedule } from "@/components/event/public-all-schedule";
import { PillTabs } from "@/components/event/pill-tabs";
import { EventTimezoneProvider } from "@/components/event/event-timezone";
import { PhotoGallery, SponsorStrip } from "@/components/event/public-media";
import { TeamRosterDialog } from "@/components/event/team-roster-dialog";
import { ViewBeacon } from "@/components/event/view-beacon";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { Input } from "@/components/ui/input";
import { EVENT_STATUS_LABELS, rupiah } from "@/lib/labels";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { isKnockout as isKnockoutFormat, isHybrid as isHybridFormat, crestGradient } from "@/lib/bracket";
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

/** Sentinel category id for the combined, all-categories schedule. */
const ALL_CATEGORIES = "all";

export default function PublicEventPage() {
  const { sportLabel, sportColor: colorOf, formatLabel } = useCatalog();
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const base = `/${params.orgSlug}/${params.eventSlug}`;
  const [tab, setTab] = useState<TabKey>("info");
  const [openTeam, setOpenTeam] = useState<PublicTeam | null>(null);
  const [teamSearch, setTeamSearch] = useState("");
  // Which category's schedule/standings/bracket the competition tabs show —
  // always a real category, never "Semua".
  const [categoryId, setCategoryId] = useState<string>("");
  // "Semua" only applies to the schedule, so it is kept apart from the category
  // choice: switching tabs must not throw away the category the user picked.
  const [scheduleAll, setScheduleAll] = useState(false);

  const query = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
    retry: false,
  });

  // Rosters live behind a dialog, so a player hit is the one match a visitor
  // cannot spot by scanning the grid — worth matching on even though only the
  // team name is rendered on the card.
  const allTeams = query.data?.approved_teams;
  const shownTeams = useMemo(() => {
    const q = teamSearch.trim().toLowerCase();
    if (!q) return allTeams ?? [];

    return (allTeams ?? []).filter(
      (t) =>
        t.name.toLowerCase().includes(q) ||
        (t.players ?? []).some((p) => p.full_name.toLowerCase().includes(q))
    );
  }, [allTeams, teamSearch]);

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

  const categories = ev.categories ?? [];

  // Which categories a panel can actually show anything for. An event may mix
  // formats — a badminton event runs Tunggal as a league and Ganda as a knockout
  // — so the tab decides which categories are on offer, not the other way round.
  // Deriving the tabs from the *selection* instead made Bracket and Klasemen
  // vanish the moment you picked a category that lacks one.
  const categoriesFor = (key: TabKey) =>
    key === "bracket"
      ? categories.filter((c) => isKnockoutFormat(c.engine) || isHybridFormat(c.engine))
      : key === "standings"
        ? categories.filter((c) => !isKnockoutFormat(c.engine))
        : categories;

  // Top-level tabs: Info + whichever match panels *any* category can fill.
  const tabs: [TabKey, string, typeof Info][] = [
    ["info", "Info", Info],
    ["teams", "Tim Peserta", Users],
    ["schedule", "Jadwal", CalendarClock],
    ...(categoriesFor("standings").length > 0
      ? ([["standings", "Klasemen", ListOrdered]] as [TabKey, string, typeof Info][])
      : []),
    ...(categoriesFor("bracket").length > 0
      ? ([["bracket", "Bracket", Network]] as [TabKey, string, typeof Info][])
      : []),
    ["stats", "Statistik", Goal],
  ];
  const activeTab: TabKey = tabs.some(([k]) => k === tab) ? tab : "schedule";

  // The chips for this tab, and the category actually being shown. Switching to
  // Bracket while a league category is picked lands on the first category that
  // has a bracket rather than on an empty panel.
  const tabCategories = categoriesFor(activeTab);
  const selectedCategory =
    tabCategories.find((c) => c.id === categoryId) ?? tabCategories[0] ?? null;
  // Preselect the viewed category on the registration form.
  const registerHref = selectedCategory
    ? `${base}/register?category=${selectedCategory.slug}`
    : `${base}/register`;
  // Klasemen, bracket and the leaderboard are all per-category — a bracket
  // belongs to exactly one — so "Semua" is offered on the schedule alone.
  const isAll = scheduleAll && activeTab === "schedule" && categories.length > 1;

  return (
    // Kickoff times render in the venue's zone, so every visitor reads the same
    // clock the organizer set — not their own.
    <EventTimezoneProvider timezone={ev.timezone}>
      {/* Counts this visit for the organizer. Only reached once the event
          resolved, so a bad slug never reports traffic. */}
      <ViewBeacon orgSlug={params.orgSlug} eventSlug={params.eventSlug} />

      {/* ===== HERO ===== */}
      <header className="ehero">
        <div className="container ehero-inner">
          <div className="ehero-top">
            <Link href="/" className="ehero-back">
              <ArrowLeft />
              <span className="min-w-0 truncate">Didukung flo-event</span>
            </Link>
            <div className="flex shrink-0 items-center gap-2">
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
                <span className="ehero-badge">
                  {categories.length} kategori
                </span>
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
                  <Link href={registerHref} className="btn btn-primary btn-lg">
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
      <section className="etabs">
        <div className="container">
          <PillTabs
            items={tabs.map(([key, label, icon]) => ({ key, label, icon }))}
            activeKey={activeTab}
            onSelect={(key) => setTab(key as TabKey)}
          />
        </div>
      </section>

      {/* ===== INFO TAB ===== */}
      {activeTab === "info" && (
        <>
          {ev.banner_url && (
            <section className="ebanner">
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
          <section className="section" style={{ paddingTop: 48 }}>
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
                      <b>Tim</b>
                      <small>Disetujui</small>
                    </div>
                    <span className="amt" style={{ fontSize: 14 }}>
                      {ev.approved_teams_count} tim
                    </span>
                  </div>
                </div>

                <div className="card scard">
                  <h3>
                    <Trophy />
                    Kategori & Biaya
                  </h3>
                  {categories.map((c) => (
                    <div className="price-tier" key={c.id}>
                      <div>
                        <b>{c.name}</b>
                        <small>{formatLabel(c.tournament_format)}</small>
                      </div>
                      <span className="amt" style={{ fontSize: 14 }}>
                        {c.registration_fee > 0 ? rupiah(c.registration_fee) : "Gratis"}
                      </span>
                    </div>
                  ))}
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
                      <Link href={registerHref} className="btn btn-primary btn-block">
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
      {activeTab === "teams" && (
        <section className="section">
          <div className="container">
            <div className="esection-title flex-wrap">
              <h2 className="section-title" style={{ margin: 0 }}>
                Tim Peserta
              </h2>
              {allTeams && allTeams.length > 0 && (
                <>
                  {/* `.esection-title .pill` pushes the count to the far right;
                      here it belongs beside the heading, so the search box takes
                      over the ml-auto. The count follows the search so it never
                      promises more teams than the grid below actually shows. */}
                  <span className="pill ml-0!">
                    {teamSearch.trim() ? `${shownTeams.length} dari ${allTeams.length} tim` : `${allTeams.length} tim`}
                  </span>
                  <div className="relative w-full sm:ml-auto sm:w-64">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      value={teamSearch}
                      onChange={(e) => setTeamSearch(e.target.value)}
                      placeholder="Cari tim atau pemain…"
                      className="pl-9 pr-9"
                      aria-label="Cari tim peserta"
                    />
                    {teamSearch && (
                      <button
                        type="button"
                        onClick={() => setTeamSearch("")}
                        aria-label="Hapus pencarian"
                        className="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-[var(--bg-soft)] hover:text-foreground"
                      >
                        <X className="h-3.5 w-3.5" />
                      </button>
                    )}
                  </div>
                </>
              )}
            </div>

            {shownTeams.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {shownTeams.map((t) => (
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
            ) : teamSearch.trim() ? (
              <p className="section-sub" style={{ margin: 0 }}>
                Tidak ada tim atau pemain yang cocok dengan “{teamSearch.trim()}”.
              </p>
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
      {activeTab !== "info" && activeTab !== "teams" && selectedCategory && (
        <>
          {tabCategories.length > 1 && (
            <section className="efilter">
              <div className="container">
                <PillTabs
                  tone="tint"
                  items={[
                    ...(activeTab === "schedule"
                      ? [{ key: ALL_CATEGORIES, label: "Semua" }]
                      : []),
                    ...tabCategories.map((c) => ({ key: c.id, label: c.name })),
                  ]}
                  activeKey={isAll ? ALL_CATEGORIES : (selectedCategory?.id ?? "")}
                  onSelect={(key) => {
                    if (key === ALL_CATEGORIES) {
                      setScheduleAll(true);
                      return;
                    }
                    setScheduleAll(false);
                    setCategoryId(key);
                  }}
                />
              </div>
            </section>
          )}
          {isAll ? (
            <PublicAllSchedule
              orgSlug={params.orgSlug}
              eventSlug={params.eventSlug}
              categories={categories}
            />
          ) : (
            <PublicResults
              key={selectedCategory!.id}
              orgSlug={params.orgSlug}
              eventSlug={params.eventSlug}
              categorySlug={selectedCategory!.slug}
              engine={selectedCategory!.engine}
              bracketConfig={selectedCategory!.bracket_config}
              usesRubbers={selectedCategory!.uses_rubbers}
              activeTab={activeTab}
            />
          )}
        </>
      )}

      {/* ===== SPONSORS & PARTNERS ===== */}
      {/* Lifted out of the Info tab so it shows on every tab, above the CTA. */}
      {ev.sponsors && ev.sponsors.length > 0 && (
        <section className="section" style={{ paddingTop: 0, paddingBottom: ev.registration_is_open ? 48 : undefined }}>
          <div className="container">
            <SponsorStrip sponsors={ev.sponsors} />
          </div>
        </section>
      )}

      {/* ===== REGISTER CTA ===== */}
      {ev.registration_is_open && (
        <section className="section" style={{ paddingTop: 0 }}>
          <div className="container">
            <div className="ereg">
              <h2>Siap bertanding di {ev.name}?</h2>
              <p>Daftarkan timmu, lengkapi data pemain, dan ikuti keseruan turnamennya.</p>
              <div className="ehero-cta">
                <Link href={registerHref} className="btn btn-primary btn-lg">
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
    </EventTimezoneProvider>
  );
}
