"use client";

import { Suspense, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import {
  ArrowLeft,
  CalendarDays,
  ChevronRight,
  MapPin,
  Users,
  Wallet,
  Trophy,
  Building2,
  Ticket,
  Info,
  Network,
  CalendarClock,
  ListOrdered,
  Goal,
  Search,
  X,
} from "lucide-react";

import { getPublicEvent } from "@/lib/api/events";
import { getPublicRace } from "@/lib/api/race";
import { PublicAuthActions } from "@/components/auth/public-auth-actions";
import {
  PublicResults,
  type ResultsTab,
} from "@/components/event/public-results";
import { PublicAllSchedule } from "@/components/event/public-all-schedule";
import { PillTabs } from "@/components/event/pill-tabs";
import { EventTimezoneProvider } from "@/components/event/event-timezone";
import { PhotoGallery, SponsorStrip } from "@/components/event/public-media";
import { TeamRosterDialog } from "@/components/event/team-roster-dialog";
import { ViewBeacon } from "@/components/event/view-beacon";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { Logo } from "@/components/shared/logo";
import { Input } from "@/components/ui/input";
import { EVENT_STATUS_LABELS, rupiah } from "@/lib/labels";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { useEventBase } from "@/lib/event-base";
import { useUrlState } from "@/lib/hooks/use-url-state";
import {
  isKnockout as isKnockoutFormat,
  isHybrid as isHybridFormat,
  crestGradient,
} from "@/lib/bracket";
import { showsPlayerStats } from "@/lib/scoring";
import { isRaceEvent } from "@/lib/race";
import type { EventStatus, PublicEvent, PublicTeam } from "@/types/api";
import "../../event-shell.css";

function fmtDate(d: string | null) {
  if (!d) return "";
  return new Date(d).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function dateRange(a: string | null, b: string | null) {
  if (!a && !b) return "Tanggal menyusul";
  if (a && b) return `${fmtDate(a)} – ${fmtDate(b)}`;
  return fmtDate(a ?? b);
}

/** Event yang sudah mulai — atau sudah tidak akan mulai lagi. */
function hasStarted(status: EventStatus) {
  return (
    status === "ongoing" || status === "finished" || status === "cancelled"
  );
}

/**
 * Kartu penyelenggara di sidebar tab Info. Seluruh kartunya adalah link ke
 * profil penyelenggara; kalau slug-nya tidak ada, kartunya tetap tampil tapi
 * sebagai teks biasa (tanpa hover state maupun chevron).
 */
function OrganizerCard({
  organization,
}: {
  organization: PublicEvent["organization"];
}) {
  const body = (
    <>
      <span className="crest">
        {organization.logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={organization.logo_url} alt="" />
        ) : (
          <Building2 />
        )}
      </span>
      <span className="meta">
        <small>Penyelenggara</small>
        <b>{organization.name ?? "-"}</b>
      </span>
      {organization.slug && <ChevronRight className="go" aria-hidden />}
    </>
  );

  if (!organization.slug)
    return <div className="card scard org-card">{body}</div>;

  return (
    <Link href={`/${organization.slug}`} className="card scard org-card">
      {body}
    </Link>
  );
}

type TabKey = "info" | "teams" | ResultsTab;

/** Sentinel category slug for the combined, all-categories schedule. */
const ALL_CATEGORIES = "all";

function PageLoading() {
  return (
    <div
      className="container"
      style={{
        paddingBlock: 96,
        textAlign: "center",
        color: "var(--text-muted)",
      }}
    >
      Memuat…
    </div>
  );
}

/**
 * Wrapped because the view below reads the query string, and useSearchParams()
 * needs a Suspense boundary above it to build.
 */
export default function PublicEventPage() {
  return (
    <Suspense fallback={<PageLoading />}>
      <PublicEventView />
    </Suspense>
  );
}

function PublicEventView() {
  const { sportLabel, sportColor: colorOf, formatLabel } = useCatalog();
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  // Di custom domain event ini adalah root, jadi `base` kosong dan tautan yang
  // butuh domain utama (pendaftaran, beranda platform) jadi absolut.
  const { base, onCustomDomain, mainUrl, homeUrl, catalogUrl } = useEventBase();
  // Tab dan kategori hidup di URL supaya refresh — atau back dari halaman
  // pendaftaran/tiket — mendarat di tempat yang sama, dan tautannya bisa
  // di-share. Kategori dikunci lewat slug, kosakata publik yang sama dengan
  // `?category=` di form pendaftaran.
  const { params: urlState, setParams } = useUrlState();
  const tab = (urlState.get("tab") ?? "info") as TabKey;
  const categoryParam = urlState.get("category") ?? "";
  const [openTeam, setOpenTeam] = useState<PublicTeam | null>(null);
  const [teamSearch, setTeamSearch] = useState("");
  // "Semua" only applies to the schedule, so the category the user picked is
  // remembered apart from it: switching tabs must not throw that choice away.
  const [lastCategorySlug, setLastCategorySlug] = useState("");

  const query = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
    retry: false,
  });

  /**
   * A race event's categories, which live in `race_categories` and are NOT in
   * the event payload.
   *
   * `PublicEventResource.categories` is `event_categories` — the competition
   * ones, read by the schedule, the standings and the bracket. A fun run has
   * zero of those, which is why the "Kategori & Biaya" card rendered its header
   * over nothing at all. Kept its own endpoint rather than widened into the
   * event payload because the price depends on the window in force, and the
   * server is the only thing that may decide which window that is (see the
   * invariant on RacePricing).
   *
   * Up here with the event query, not beside the `isRace` it reads: everything
   * below the early returns runs conditionally, and a hook there changes call
   * order between the loading and loaded renders. `isRaceEvent` is asked of
   * `query.data` directly for the same reason — `ev` does not exist yet.
   *
   * `enabled` keeps a football event from asking at all: the route 404s for
   * one, and a failed query would sit retrying behind a card that is correctly
   * showing nothing.
   */
  const raceQuery = useQuery({
    // The same key the registration form uses, so arriving there from this page
    // costs no second request.
    queryKey: ["public-race", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicRace(params.orgSlug, params.eventSlug),
    enabled: isRaceEvent(query.data),
    retry: false,
  });

  // Rosters live behind a dialog, so a player or official hit is the one match a
  // visitor cannot spot by scanning the grid — worth matching on even though
  // only the team name is rendered on the card.
  const allTeams = query.data?.approved_teams;
  const shownTeams = useMemo(() => {
    const q = teamSearch.trim().toLowerCase();
    if (!q) return allTeams ?? [];

    return (allTeams ?? []).filter(
      (t) =>
        t.name.toLowerCase().includes(q) ||
        // Now that the card prints the category, it has to be searchable too —
        // a term visible on screen that matches nothing reads as a broken box.
        (t.category?.name.toLowerCase().includes(q) ?? false) ||
        (t.players ?? []).some((p) => p.full_name.toLowerCase().includes(q)) ||
        (t.officials ?? []).some((o) => o.full_name.toLowerCase().includes(q)),
    );
  }, [allTeams, teamSearch]);

  if (query.isLoading) return <PageLoading />;

  if (query.isError || !query.data) {
    return (
      <div
        className="container"
        style={{ paddingBlock: 96, textAlign: "center" }}
      >
        <h1 className="section-title">Event tidak ditemukan</h1>
        <p className="section-sub">
          Periksa kembali tautannya atau event belum dipublikasikan.
        </p>
        <Link
          href={homeUrl}
          className="btn btn-primary btn-lg"
          style={{ marginTop: 24 }}
        >
          Ke beranda
        </Link>
      </div>
    );
  }

  const ev = query.data;
  const sportColor = colorOf(ev.sport_type);

  const categories = ev.categories ?? [];

  // Racket sports don't publish player stats — see showsPlayerStats. Resolved
  // once here so the tab and the panels below can't disagree about it.
  const playerStats = showsPlayerStats(ev.sport);

  // A fun run holds no fixtures at all, so five of the six tabs below can never
  // fill — Info is the whole public page. Resolved once here for the same
  // reason `playerStats` is: the tab bar and the panels must not disagree.
  //
  // "Tim Peserta" goes too, and that is a privacy decision rather than an
  // emptiness one. It renders `approved_teams`, which a race event has none of,
  // so it would simply be blank — but the fix is NOT to publish the runners
  // there instead. A squad roster is public because a squad is public: names
  // and shirt numbers are already on the schedule, the standings and the
  // leaderboard. A `race_entries` row carries an email, a phone number and an
  // emergency contact, which is why even the organizer's own list of them sits
  // behind `org.admin`. Nobody asked for a public start list, and that is a
  // decision to take deliberately if it is ever wanted — with its own payload
  // that publishes a name and nothing else.
  const isRace = isRaceEvent(ev);

  // Fed by the query up beside the event one — see its docblock for why it
  // cannot live here.
  const raceCategories = raceQuery.data?.categories ?? [];
  const racePeriod = raceQuery.data?.current_period ?? null;

  // Satu-satunya penjawab "berapa kategori event ini" di halaman ini, dibaca
  // badge hero dan kartu "Kategori & Biaya". Dua salinan `isRace ? … : …` akan
  // menyimpang, dan penyimpangannya persis bug yang ada: badge berkata nol di
  // atas kartu yang mendaftar tiga.
  const categoryCount = isRace ? raceCategories.length : categories.length;

  // Which categories a panel can actually show anything for. An event may mix
  // formats — a badminton event runs Tunggal as a league and Ganda as a knockout
  // — so the tab decides which categories are on offer, not the other way round.
  // Deriving the tabs from the *selection* instead made Bracket and Klasemen
  // vanish the moment you picked a category that lacks one.
  const categoriesFor = (key: TabKey) =>
    key === "bracket"
      ? categories.filter(
          (c) => isKnockoutFormat(c.engine) || isHybridFormat(c.engine),
        )
      : key === "standings"
        ? categories.filter((c) => !isKnockoutFormat(c.engine))
        : categories;

  // Top-level tabs: Info + whichever match panels *any* category can fill.
  const tabs: [TabKey, string, typeof Info][] = [
    ["info", "Info", Info],
    ...(isRace
      ? []
      : ([
          ["teams", "Tim Peserta", Users],
          ["schedule", "Jadwal", CalendarClock],
        ] as [TabKey, string, typeof Info][])),
    ...(categoriesFor("standings").length > 0
      ? ([["standings", "Klasemen", ListOrdered]] as [
          TabKey,
          string,
          typeof Info,
        ][])
      : []),
    ...(categoriesFor("bracket").length > 0
      ? ([["bracket", "Bracket", Network]] as [TabKey, string, typeof Info][])
      : []),
    // Unlike the two above, this one is decided per event, not per category:
    // the sport lives on the event, so no category can bring the tab back.
    // (Klasemen and Bracket need no race branch: a fun run has no
    // `event_categories` at all, so `categoriesFor()` is empty for both.)
    ...(playerStats && !isRace
      ? ([["stats", "Statistik", Goal]] as [TabKey, string, typeof Info][])
      : []),
  ];
  // The fallback has to be a tab that EXISTS, and it is still "schedule"
  // wherever there is one — a competition event's default landing tab must not
  // move. On a fun run there is no schedule, so "teams" takes it: that is the
  // panel holding the start list. Not `tabs[tabs.length - 1]`, which would be
  // Statistik or Bracket on an ordinary event and quietly change where every
  // stale link lands.
  // Still "schedule" wherever there is one — a competition event's default
  // landing tab must not move. A fun run has only Info, so that is where a
  // stale `?tab=schedule` link lands instead of on an empty panel.
  const fallbackTab: TabKey = isRace ? "info" : "schedule";
  const activeTab: TabKey = tabs.some(([k]) => k === tab) ? tab : fallbackTab;

  // The chips for this tab, and the category actually being shown. Switching to
  // Bracket while a league category is picked lands on the first category that
  // has a bracket rather than on an empty panel.
  const tabCategories = categoriesFor(activeTab);
  // On "Semua" the URL holds no real category, so the last one clicked stands in
  // for it on the tabs that can't show a combined view.
  const wantedSlug =
    categoryParam === ALL_CATEGORIES ? lastCategorySlug : categoryParam;
  const selectedCategory =
    tabCategories.find((c) => c.slug === wantedSlug) ??
    tabCategories[0] ??
    null;
  // Preselect the viewed category on the registration form.
  // Selalu ke domain utama: pendaftaran butuh sesi, dan refresh cookie terikat
  // `.floevent.id` sehingga tidak akan pernah terkirim ke domain organizer.
  //
  // Two forms, one button. A fun run's entrants are runners, and `/register`
  // renders a team form over `event_categories` — which a race event has none
  // of, so it would open an empty category select. The race form picks its own
  // category, hence no `?category=`: these are `race_categories` and the slug
  // above belongs to a competition category that does not exist here.
  const registerHref = isRace
    ? mainUrl("/race")
    : selectedCategory
      ? mainUrl(`/register?category=${selectedCategory.slug}`)
      : mainUrl("/register");
  // Three CTAs say the same thing, so the word is picked once. "Tim" is wrong
  // for a solo entrant, and the `fun_run` branch covers a training run and a
  // charity walk as well as a race — so the neutral verb is used rather than a
  // second noun that would be wrong for two of the three.
  const registerLabel = isRace ? "Daftar Sekarang" : "Daftar Tim Sekarang";
  // Klasemen, bracket and the leaderboard are all per-category — a bracket
  // belongs to exactly one — so "Semua" is offered on the schedule alone.
  const isAll =
    categoryParam === ALL_CATEGORIES &&
    activeTab === "schedule" &&
    categories.length > 1;
  // "Pendaftaran ditutup" hanya berarti sesuatu selama eventnya belum mulai.
  // Begitu berlangsung/selesai/batal, statusnya sudah tampil di badge hero dan
  // pill itu cuma jadi tombol mati di bawah judul.
  //
  // Dan `online_registration_enabled` adalah syarat ketiga: event yang paketnya
  // tidak pernah punya form pendaftaran publik tidak pernah "ditutup", jadi
  // mengumumkannya cuma menimbulkan pertanyaan soal pintu yang tidak ada.
  // Diamnya yang benar — pesertanya datang lewat organizer.
  const showRegistrationClosed =
    ev.online_registration_enabled &&
    !ev.registration_is_open &&
    !hasStarted(ev.status);
  const showHeroCta =
    ev.registration_is_open || showRegistrationClosed || ev.tickets_on_sale;

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
            <Link href={catalogUrl} className="ehero-back">
              <ArrowLeft />
              <span className="min-w-0 truncate">Didukung flo-event</span>
            </Link>
            <div className="flex shrink-0 items-center gap-2">
              {/* Widget akun disembunyikan di custom domain: sesinya memang tidak
                  ikut ke sini, jadi ia hanya akan menawarkan login yang gagal. */}
              {!onCustomDomain && <PublicAuthActions />}
              <ThemeToggleButton />
            </div>
          </div>

          <div className="ehero-grid">
            <div>
              <div className="ehero-badges">
                <span
                  className="ehero-badge sport"
                  style={{ color: sportColor }}
                >
                  {sportLabel(ev.sport_type)}
                </span>
                {/* Dua tabel, dan badge ini harus membaca yang sama dengan
                    kartu "Kategori & Biaya" di bawah: `categories` adalah
                    `event_categories`, yang fun run **tidak punya satu pun** —
                    membacanya polos di sini mengumumkan "0 kategori" di atas
                    kartu yang mendaftar tiga. Hilang saat nol, bukan mencetak
                    nol: `raceQuery` dimuat terpisah dari event, jadi angka itu
                    juga keadaan "belum tahu", dan heading tanpa isi sudah jadi
                    alasan kartunya ikut hilang seluruhnya. */}
                {categoryCount > 0 && (
                  <span className="ehero-badge">{categoryCount} kategori</span>
                )}
                {/* Badge ini **status event**, bukan ajakan — jadi ia membaca
                    `ev.status` dan tetap tampil apa pun paketnya, termasuk
                    berbunyi "Pendaftaran Dibuka" pada event yang form publiknya
                    tidak ada. Itu disengaja: di sini tidak ada yang bisa diklik,
                    dan `status` memang `open`. Yang digerbang `online_registration`
                    adalah hal yang mengajak orang bertindak — pill CTA di bawah,
                    kartu Pendaftaran di sidebar, CTA bawah halaman. */}
                <span className="ehero-badge">
                  <span
                    style={{
                      width: 7,
                      height: 7,
                      borderRadius: "50%",
                      background: ev.registration_is_open
                        ? "#22D3A7"
                        : "rgba(255,255,255,0.6)",
                      display: "inline-block",
                    }}
                  />
                  {ev.registration_is_open
                    ? "Pendaftaran Dibuka"
                    : EVENT_STATUS_LABELS[ev.status]}
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
                {/* `approved_teams_count` counts `teams`, which a fun run has
                    none of — the pill would read "0 tim" over an event that
                    sold three hundred entries. Dropped rather than swapped for
                    an entry count: that number is only meaningful next to a
                    quota, and it belongs on the race form where the quota is. */}
                {!isRace && (
                  <span>
                    <Users />
                    {ev.approved_teams_count} tim
                  </span>
                )}
              </div>

              {/* .ehero-cta punya margin-top sendiri, jadi wadahnya ikut hilang
                  kalau tidak ada satu pun tombol — bukan cuma isinya. */}
              {showHeroCta && (
                <div className="ehero-cta">
                  {ev.registration_is_open && (
                    <Link
                      href={registerHref}
                      className="btn btn-primary btn-lg"
                    >
                      {registerLabel}
                    </Link>
                  )}
                  {showRegistrationClosed && (
                    <span
                      className="ehero-badge"
                      style={{ height: 44, paddingInline: 20 }}
                    >
                      Pendaftaran ditutup
                    </span>
                  )}
                  {ev.tickets_on_sale && (
                    <Link
                      href={`${base}/tickets`}
                      className="btn btn-secondary btn-lg"
                    >
                      Beli Tiket
                    </Link>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      </header>

      {/* ===== TABS =====
          Gone entirely when there is only one, which on a fun run is always:
          Info is the only panel it can fill, so the strip would be a single
          pill with nowhere else to go. Written as "more than one" rather than
          `!isRace` so an ordinary event whose categories can fill nothing yet
          loses it too — the condition is "is there a choice", and a second
          reader spelled as a sport check would disagree with this one the first
          time a competition event has no schedule either.

          Derived from `tabs`, the same array that renders the pills, so the two
          cannot drift. `.etabs` carries its own border and padding, so hiding
          only the pills would leave a visible seam — the same call Proof()
          makes by returning null instead of an empty strip. */}
      {tabs.length > 1 && (
        <section className="etabs">
          <div className="container">
            <PillTabs
              items={tabs.map(([key, label, icon]) => ({ key, label, icon }))}
              activeKey={activeTab}
              // Info adalah default, jadi ia tidak perlu ditulis ke URL.
              onSelect={(key) =>
                setParams({ tab: key === "info" ? undefined : key })
              }
            />
          </div>
        </section>
      )}

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
                {/* dangerouslySetInnerHTML is safe here for the same reason it
                    is on the article page: `events.description` only ever holds
                    HTML that went through App\Support\HtmlSanitizer — see the
                    mutator on the Event model, which is what makes every reader
                    of this column safe without repeating a filter here. */}
                {ev.description ? (
                  <div
                    className="rich-prose"
                    dangerouslySetInnerHTML={{ __html: ev.description }}
                  />
                ) : (
                  <p className="section-sub" style={{ margin: 0 }}>
                    Belum ada deskripsi untuk event ini.
                  </p>
                )}

                {ev.photos && <PhotoGallery photos={ev.photos} />}
              </div>

              {/* sidebar */}
              <aside>
                {/*
                  Cabang, lokasi, tanggal, dan jumlah tim sudah tampil di hero,
                  jadi sidebar hanya menyisakan penyelenggara — dan seluruh
                  kartunya jadi link supaya afordansi kliknya jelas.
                */}
                <OrganizerCard organization={ev.organization} />

                {/* The card goes entirely when there is nothing in it. Its
                    header used to render over an empty list for every race
                    event, and a heading with no rows under it reads as broken
                    rather than as "none yet" — the same call Proof() makes by
                    returning null instead of a lone label. */}
                {categoryCount > 0 && (
                  <div className="card scard">
                    <h3>
                      <Trophy />
                      Kategori & Biaya
                    </h3>
                    {isRace
                      ? raceCategories.map((c) => (
                          <div className="price-tier" key={c.id}>
                            <div>
                              <b>{c.name}</b>
                              {/* Which window the price belongs to, because the
                                  same category costs three different amounts
                                  over the sale and a number with no window
                                  beside it cannot be checked against anything.
                                  The sub-line carries the quota when the
                                  category is not on sale right now, since the
                                  price then has no window to name. */}
                              <small>
                                {c.current_price !== null
                                  ? (racePeriod?.name ?? "Periode berjalan")
                                  : "Tidak dijual pada periode ini"}
                                {c.remaining !== null &&
                                  ` · sisa ${c.remaining} kuota`}
                              </small>
                            </div>
                            <span className="amt" style={{ fontSize: 14 }}>
                              {/* Null is not zero: zero is a real price (a
                                  sponsored run), so `?? 0` here would print
                                  "Gratis" over a category nobody can enter. */}
                              {c.current_price === null
                                ? "—"
                                : c.current_price > 0
                                  ? rupiah(c.current_price)
                                  : "Gratis"}
                            </span>
                          </div>
                        ))
                      : categories.map((c) => (
                          <div className="price-tier" key={c.id}>
                            <div>
                              <b>{c.name}</b>
                              <small>{formatLabel(c.tournament_format)}</small>
                            </div>
                            <span className="amt" style={{ fontSize: 14 }}>
                              {c.registration_fee > 0
                                ? rupiah(c.registration_fee)
                                : "Gratis"}
                            </span>
                          </div>
                        ))}
                  </div>
                )}

                {/* Aturan yang sama dengan CTA hero: kartunya hilang seluruhnya
                    begitu event berjalan, bukan cuma tombolnya. */}
                {(ev.registration_is_open || showRegistrationClosed) && (
                  <div className="card scard">
                    <h3>
                      <Wallet />
                      Pendaftaran
                    </h3>
                    {ev.registration_is_open ? (
                      <>
                        <p
                          style={{
                            fontSize: 14,
                            color: "var(--text-muted)",
                            marginBottom: 16,
                          }}
                        >
                          {isRace
                            ? "Pendaftaran sedang dibuka. Amankan slotmu sekarang."
                            : "Pendaftaran tim sedang dibuka. Amankan slot timmu sekarang."}
                        </p>
                        <Link
                          href={registerHref}
                          className="btn btn-primary btn-block"
                        >
                          <Trophy className="h-4 w-4" />
                          {isRace ? "Daftar" : "Daftar Tim"}
                        </Link>
                      </>
                    ) : (
                      <p
                        style={{
                          fontSize: 14,
                          color: "var(--text-muted)",
                          margin: 0,
                        }}
                      >
                        Pendaftaran untuk event ini sedang ditutup.
                      </p>
                    )}
                  </div>
                )}

                {ev.tickets_on_sale && (
                  <div className="card scard">
                    <h3>
                      <Ticket />
                      Tiket Penonton
                    </h3>
                    <p
                      style={{
                        fontSize: 14,
                        color: "var(--text-muted)",
                        marginBottom: 16,
                      }}
                    >
                      Tiket digital tersedia. Beli sekarang dan masuk dengan QR
                      Code.
                    </p>
                    <Link
                      href={`${base}/tickets`}
                      className="btn btn-primary btn-block"
                    >
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
                    {teamSearch.trim()
                      ? `${shownTeams.length} dari ${allTeams.length} tim`
                      : `${allTeams.length} tim`}
                  </span>
                  <div className="relative w-full sm:ml-auto sm:w-64">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      value={teamSearch}
                      onChange={(e) => setTeamSearch(e.target.value)}
                      placeholder="Cari tim, pemain, atau kategori…"
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
                        style={{
                          width: 40,
                          height: 40,
                          borderRadius: 10,
                          border: "1px solid var(--border)",
                        }}
                      />
                    ) : (
                      <span
                        className="crest"
                        style={{
                          width: 40,
                          height: 40,
                          borderRadius: 10,
                          background: crestGradient(t.name),
                        }}
                      />
                    )}
                    <div className="min-w-0">
                      <div className="match-team" style={{ gap: 0 }}>
                        <span className="truncate">{t.name}</span>
                      </div>
                      {/* Which competition they entered — the one thing that
                          tells two same-named squads apart in an event that
                          runs several categories at once. */}
                      {(t.category || t.group_name) && (
                        <div
                          className="mt-0.5 truncate text-xs"
                          style={{ color: "var(--text-muted)" }}
                        >
                          {[t.category?.name, t.group_name && `Grup ${t.group_name}`]
                            .filter(Boolean)
                            .join(" · ")}
                        </div>
                      )}
                    </div>
                    <span className="pill shrink-0">
                      {t.players?.length ?? 0} pemain
                    </span>
                  </button>
                ))}
              </div>
            ) : teamSearch.trim() ? (
              <p className="section-sub" style={{ margin: 0 }}>
                Tidak ada tim atau pemain yang cocok dengan “{teamSearch.trim()}
                ”.
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
          orgSlug={params.orgSlug}
          eventSlug={params.eventSlug}
          playerStats={playerStats}
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
                    ...tabCategories.map((c) => ({
                      key: c.slug,
                      label: c.name,
                    })),
                  ]}
                  activeKey={
                    isAll ? ALL_CATEGORIES : (selectedCategory?.slug ?? "")
                  }
                  onSelect={(key) => {
                    if (key !== ALL_CATEGORIES) setLastCategorySlug(key);
                    setParams({ category: key });
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
              playerStats={playerStats}
              sport={ev.sport ?? null}
            />
          ) : (
            <PublicResults
              key={selectedCategory!.id}
              orgSlug={params.orgSlug}
              eventSlug={params.eventSlug}
              categorySlug={selectedCategory!.slug}
              engine={selectedCategory!.engine}
              bracketConfig={selectedCategory!.bracket_config}
              context={selectedCategory!.standings_context}
              activeTab={activeTab}
              playerStats={playerStats}
              sport={ev.sport ?? null}
            />
          )}
        </>
      )}

      {/* ===== SPONSORS & PARTNERS ===== */}
      {/* Lifted out of the Info tab so it shows on every tab, above the CTA. */}
      {ev.sponsors && ev.sponsors.length > 0 && (
        <section
          className="section"
          style={{
            paddingTop: 0,
            paddingBottom: ev.registration_is_open ? 48 : undefined,
          }}
        >
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
              {/* "Bertanding" and "turnamen" are competition words: a fun run
                  has no opponent, and the same branch covers a training run. */}
              <h2>
                {isRace ? `Ikut ${ev.name}?` : `Siap bertanding di ${ev.name}?`}
              </h2>
              <p>
                {isRace
                  ? "Isi datamu, pilih kategori, lalu selesaikan pembayaran."
                  : "Daftarkan timmu, lengkapi data pemain, dan ikuti keseruan turnamennya."}
              </p>
              <div className="ehero-cta">
                <Link href={registerHref} className="btn btn-primary btn-lg">
                  {registerLabel}
                </Link>
              </div>
            </div>
          </div>
        </section>
      )}

      {/* ===== FOOTER ===== */}
      <footer className="footer !py-7">
        <div className="container">
          <div
            className="footer-bottom"
            style={{ marginTop: 0, paddingTop: 0, borderTop: "none" }}
          >
            <Logo href={homeUrl} />
            <span>Halaman event ini dibuat dengan floevent · © 2026</span>
          </div>
        </div>
      </footer>
    </EventTimezoneProvider>
  );
}
