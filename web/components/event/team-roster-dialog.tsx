"use client";

import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Info, Layers, UserCog, Users, X } from "lucide-react";

import { getPublicLeaderboard } from "@/lib/api/matches";
import { crestGradient } from "@/lib/bracket";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { statIcon } from "@/lib/stat-icons";
import { participantLabel, usesSquadFields } from "@/lib/scoring";
import type { PublicAnswer } from "@/lib/registration-form";
import type { PublicTeam, StatColumn } from "@/types/api";

/** "Ammar Wijaya" → "AW". Two words at most, so a long name still fits. */
function initials(name: string) {
  return name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

/** An uploaded photo, or null — a stored path that isn't a URL renders nothing. */
function photoOf(url: string | null | undefined) {
  return url && /^https?:\/\//.test(url) ? url : null;
}

/**
 * Everything published about one person, as the detail layer shows it.
 *
 * Assembled by the card that was clicked rather than at the two call sites, so
 * the roster and the bench cannot drift on what a detail contains.
 */
type PersonDetail = {
  name: string;
  photo: string | null;
  badge: string | null;
  role: string | null;
  answers: PublicAnswer[];
  stats?: Record<string, number>;
  columns: StatColumn[];
};

export function TeamRosterDialog({
  team,
  sport,
  orgSlug,
  eventSlug,
  playerStats,
  onClose,
}: {
  team: PublicTeam;
  /** Sport slug — a roster stores position keys, not the words to show. */
  sport?: string | null;
  orgSlug: string;
  eventSlug: string;
  /**
   * Whether this sport publishes player stats at all — see showsPlayerStats.
   * Resolved by the caller, the same way MatchDetailDialog takes it, so the
   * Statistik tab and this dialog can't disagree about whether they exist.
   */
  playerStats: boolean;
  onClose: () => void;
}) {
  const { positionLabel, officialRoleLabel, sport: sportDef } = useCatalog();
  const [detail, setDetail] = useState<PersonDetail | null>(null);

  // No shirt numbers in the racket family, so the slot in front of the name
  // holds the player's initial instead of a dash for every row.
  const squadFields = usesSquadFields(sportDef(sport));

  useEffect(() => {
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = "";
    };
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      // Two layers, and Escape peels exactly one: the detail first, the roster
      // only once nothing sits on top of it. Closing both would turn opening a
      // player into a way to lose the squad behind them.
      if (detail) setDetail(null);
      else onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [detail, onClose]);

  const players = team.players ?? [];
  const officials = team.officials ?? [];
  const teamAnswers = team.custom_fields ?? [];
  const categorySlug = team.category?.slug ?? null;

  // What each player did over the tournament. Same query key and shape as the
  // Statistik tab (see public-results.tsx), so opening a roster after reading
  // the leaderboard — or the other way round — costs no second request.
  const leaderboard = useQuery({
    queryKey: ["public-leaderboard", orgSlug, eventSlug, categorySlug],
    queryFn: () => getPublicLeaderboard(orgSlug, eventSlug, categorySlug!),
    retry: false,
    enabled: playerStats && !!categorySlug,
  });

  /** player_id => their totals. Absent means they have yet to register one. */
  const statsById = useMemo(() => {
    const map = new Map<string, Record<string, number>>();
    for (const row of leaderboard.data?.rows ?? []) map.set(row.player_id, row.stats);
    return map;
  }, [leaderboard.data]);

  const columns = leaderboard.data?.columns ?? [];

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`Skuad ${team.name}`}
      onClick={onClose}
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 backdrop-blur-sm sm:items-center sm:p-6"
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-t-2xl border border-border bg-[var(--surface)] shadow-xl sm:max-h-[85vh] sm:rounded-2xl"
      >
        <div className="flex items-start gap-3 border-b border-border p-4 sm:p-5">
          {team.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={team.logo_url}
              alt={team.name}
              className="shrink-0 object-cover"
              style={{ width: 56, height: 56, borderRadius: 14, border: "1px solid var(--border)" }}
            />
          ) : (
            <span
              className="crest shrink-0"
              style={{ width: 56, height: 56, borderRadius: 14, background: crestGradient(team.name) }}
            />
          )}
          <div className="min-w-0 flex-1">
            <h3 className="truncate text-lg font-bold sm:text-xl">{team.name}</h3>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {team.category && (
                <span className="pill">
                  <Layers className="h-3.5 w-3.5" />
                  {team.category.name}
                </span>
              )}
              {team.group_name && <span className="pill">Grup {team.group_name}</span>}
              {team.category && (
                <span className="pill">{participantLabel(team.category.participant_type)}</span>
              )}
              <span className="pill">
                <Users className="h-3.5 w-3.5" />
                {players.length} pemain
              </span>
              {officials.length > 0 && (
                <span className="pill">
                  <UserCog className="h-3.5 w-3.5" />
                  {officials.length} ofisial
                </span>
              )}
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Tutup"
            className="shrink-0 rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-[var(--surface-2)] hover:text-foreground"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="overflow-y-auto p-4 sm:p-5">
          {/* What was asked about the squad itself — alamat, asal sekolah —
              and published. Sits above the roster rather than in the header:
              these are answers of arbitrary length, and the header is a row
              of pills. */}
          {teamAnswers.length > 0 && (
            <dl className="mb-5 grid gap-3 rounded-xl border border-border bg-[var(--surface-2)] p-3 sm:grid-cols-2 lg:grid-cols-3">
              {teamAnswers.map((a) => (
                <div key={a.key}>
                  <dt
                    className="text-[11px] uppercase tracking-wide"
                    style={{ color: "var(--text-muted)" }}
                  >
                    {a.label}
                  </dt>
                  <dd className="mt-0.5 break-words text-sm font-medium">{a.value}</dd>
                </div>
              ))}
            </dl>
          )}

          {players.length === 0 ? (
            <p className="section-sub" style={{ margin: 0 }}>
              Tim ini belum mendaftarkan pemain.
            </p>
          ) : (
            <>
              <h4 className="mb-3 inline-flex items-center gap-1.5 text-sm font-bold">
                <Users className="h-4 w-4" /> Pemain
                <span className="font-normal" style={{ color: "var(--text-muted)" }}>
                  ({players.length})
                </span>
              </h4>
              <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {players.map((p) => (
                  <PersonCard
                    key={p.id ?? p.full_name}
                    name={p.full_name}
                    photo={photoOf(p.photo_url)}
                    badge={squadFields && p.jersey_number ? `#${p.jersey_number}` : null}
                    role={p.position ? positionLabel(sport, p.position) : null}
                    stats={p.id ? statsById.get(p.id) : undefined}
                    columns={columns}
                    answers={p.custom_fields ?? []}
                    onDetail={setDetail}
                  />
                ))}
              </ul>
            </>
          )}

          {/* The bench, when there is one. No empty state: a team without
              officials hasn't left anything blank, it just doesn't list them. */}
          {officials.length > 0 && (
            <>
              <h4 className="mb-3 mt-6 inline-flex items-center gap-1.5 text-sm font-bold">
                <UserCog className="h-4 w-4" /> Pelatih &amp; Ofisial
                <span className="font-normal" style={{ color: "var(--text-muted)" }}>
                  ({officials.length})
                </span>
              </h4>
              <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {officials.map((o) => (
                  <PersonCard
                    key={o.id ?? o.full_name}
                    name={o.full_name}
                    photo={photoOf(o.photo_url)}
                    badge={null}
                    role={o.role ? officialRoleLabel(sport, o.role) : null}
                    columns={[]}
                    answers={o.custom_fields ?? []}
                    onDetail={setDetail}
                  />
                ))}
              </ul>
            </>
          )}
        </div>
      </div>

      {/* One person, in full: the photo at the size clicking it used to give,
          with what was published about them beside it. A single layer, so
          there is never a second modal to dismiss. */}
      {detail && <PersonDetailSheet person={detail} onClose={() => setDetail(null)} />}

    </div>
  );
}

/**
 * One person as a portrait card — the 3x4 photo people actually print, at a
 * size worth looking at, with everything published about them underneath.
 *
 * Shared by the roster and the bench: the two differ only in what goes in
 * `badge` (a shirt number) and `role` (a position or a jabatan), which is why
 * neither of them branches on which list it is rendering.
 */
function PersonCard({
  name,
  photo,
  badge,
  role,
  stats,
  columns,
  answers,
  onDetail,
}: {
  name: string;
  photo: string | null;
  /** Shirt number, already formatted. Null when the sport has none. */
  badge: string | null;
  /** Position or jabatan, already turned into words by the catalog. */
  role: string | null;
  /** Tournament totals, when this sport publishes them and the player has any. */
  stats?: Record<string, number>;
  columns: StatColumn[];
  /**
   * Answers to the registration fields the organizer marked public, already
   * filtered and labelled by the API. Nothing is decided here — an empty list
   * means this event publishes none, and that is the common case.
   *
   * Not rendered on the card: an answer can be an address, and a grid four
   * cards wide has no room for one. The card offers a way in instead.
   */
  answers: PublicAnswer[];
  onDetail: (person: PersonDetail) => void;
}) {
  // Only the stat kinds this player actually recorded — a row of zeroes says
  // nothing, and most of a roster has never been booked.
  const scored = columns.filter((c) => (stats?.[c.key] ?? 0) > 0);

  const open = () => onDetail({ name, photo, badge, role, answers, stats, columns });

  // Whether the detail layer would say anything the card does not. Both doors
  // into it are gated on this one answer, so the photo and the button can
  // never disagree about whether there is a detail to open.
  const hasDetail = !!photo || answers.length > 0;

  const portrait = (
    <>
      {photo ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={photo}
          alt={name}
          loading="lazy"
          className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
        />
      ) : (
        <span
          className="grid h-full w-full place-items-center text-2xl font-extrabold text-white/90"
          style={{ background: crestGradient(name), fontFamily: "var(--font-display)" }}
          aria-hidden
        >
          {initials(name)}
        </span>
      )}
      {badge && (
        <span
          className="absolute left-2 top-2 rounded-md px-1.5 py-0.5 text-xs font-bold tabular-nums"
          style={{
            background: "color-mix(in srgb, var(--surface) 85%, transparent)",
            border: "1px solid var(--border)",
            backdropFilter: "blur(4px)",
          }}
        >
          {badge}
        </span>
      )}
    </>
  );

  return (
    <li className="overflow-hidden rounded-xl border border-border bg-[var(--surface)]">
      {/* Clickable when the detail would hold something this card does not
          already show — a photo to enlarge, or a published answer. Someone
          with neither gets a plain tile, because a button around it would
          promise a layer repeating the two lines underneath it. */}
      {hasDetail ? (
        <button
          type="button"
          onClick={open}
          aria-label={`Lihat detail ${name}`}
          className="group relative block aspect-[3/4] w-full overflow-hidden bg-[var(--surface-2)]"
        >
          {portrait}
        </button>
      ) : (
        <div className="relative aspect-[3/4] w-full overflow-hidden bg-[var(--surface-2)]">
          {portrait}
        </div>
      )}

      <div className="p-2.5">
        <p className="truncate text-sm font-semibold" title={name}>
          {name}
        </p>
        <p className="mt-0.5 truncate text-xs" style={{ color: "var(--text-muted)" }}>
          {role ?? "—"}
        </p>

        {/* The same layer the portrait opens — spelled out for anyone who
            would not think to click a photo, and for keyboard users, who get
            a labelled control rather than an image. */}
        {hasDetail && (
          <button
            type="button"
            onClick={open}
            className="mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-border px-2 py-1.5 text-xs font-medium transition-colors hover:bg-[var(--surface-2)]"
          >
            <Info className="h-3.5 w-3.5" />
            Lihat detail
          </button>
        )}

        {scored.length > 0 && (
          <div className="mt-2 flex flex-wrap items-center gap-1">
            {scored.map((c) => {
              const v = stats![c.key];
              const icon = statIcon(c.key);
              return (
                <span
                  key={c.key}
                  className="pill"
                  style={{ height: 24, paddingInline: 8, fontSize: 12 }}
                  title={c.label}
                  // The icon carries no text, and the two cards differ only by
                  // colour — the label has to reach assistive tech some way.
                  aria-label={`${v} ${c.label}`}
                >
                  {v}
                  {icon ? (
                    <icon.Icon
                      className="h-3.5 w-3.5 shrink-0"
                      style={{ color: icon.color }}
                      fill={icon.filled ? "currentColor" : "none"}
                      aria-hidden="true"
                    />
                  ) : (
                    // A stat key this build has no icon for — see lib/stat-icons.
                    c.short
                  )}
                </span>
              );
            })}
          </div>
        )}
      </div>
    </li>
  );
}

/**
 * One person in full: the portrait large, and every answer the organizer
 * published, at the length it was actually written.
 *
 * This is also where an enlarged photo now lives. It used to be a third layer
 * of its own, which meant clicking a face and pressing "Lihat detail" opened
 * two different things stacked on the same roster — so the photo is simply
 * shown big here, and there is one layer to dismiss instead of two.
 *
 * A layer rather than an expanding card, because the answers are what the grid
 * has no room for: growing a cell reflows the three rows below it, and the
 * stat pills would end up further from the photo the longer someone's address
 * happens to be.
 */
function PersonDetailSheet({
  person,
  onClose,
}: {
  person: PersonDetail;
  onClose: () => void;
}) {
  const { name, photo, badge, role, answers, stats, columns } = person;
  const scored = columns.filter((c) => (stats?.[c.key] ?? 0) > 0);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`Detail ${name}`}
      // Stops the roster backdrop underneath from closing too: one click meant
      // to dismiss this person would otherwise dismiss their squad as well.
      onClick={(e) => {
        e.stopPropagation();
        onClose();
      }}
      className="fixed inset-0 z-[55] flex items-end justify-center bg-black/70 p-0 backdrop-blur-sm sm:items-center sm:p-6"
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl border border-border bg-[var(--surface)] shadow-xl sm:max-h-[85vh] sm:rounded-2xl"
      >
        <div className="flex items-start justify-between gap-3 border-b border-border p-4">
          <div className="min-w-0">
            <h4 className="truncate text-base font-bold">{name}</h4>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {badge && <span className="pill tabular-nums">{badge}</span>}
              {role && <span className="pill">{role}</span>}
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Tutup detail"
            className="shrink-0 rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-[var(--surface-2)] hover:text-foreground"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Photo beside the answers once there is width for both, stacked on a
            phone. The portrait keeps its 3:4 rather than filling the panel:
            these are the passport-style photos entrants upload, and a face
            stretched to a wide box is worse than a small one. */}
        <div className="overflow-y-auto p-4 sm:grid sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)] sm:gap-5">
          {photo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={photo}
              alt={name}
              className="mx-auto aspect-[3/4] w-48 rounded-xl border border-border object-cover sm:mx-0 sm:w-full"
            />
          ) : (
            <span
              className="mx-auto grid aspect-[3/4] w-48 place-items-center rounded-xl text-4xl font-extrabold text-white/90 sm:mx-0 sm:w-full"
              style={{ background: crestGradient(name), fontFamily: "var(--font-display)" }}
              aria-hidden
            >
              {initials(name)}
            </span>
          )}

          <div className="mt-4 min-w-0 sm:mt-0">
            {/* Label above value, not "Label: value" on one line — an answer
                can run to a full address, and wrapping it under its own label
                keeps the two readable as a pair. */}
            <dl className="grid gap-3">
              {answers.map((a) => (
                <div key={a.key} className="rounded-lg border border-border bg-[var(--surface-2)] p-3">
                  <dt
                    className="text-[11px] uppercase tracking-wide"
                    style={{ color: "var(--text-muted)" }}
                  >
                    {a.label}
                  </dt>
                  <dd className="mt-0.5 break-words text-sm font-medium">{a.value}</dd>
                </div>
              ))}
            </dl>

            {scored.length > 0 && (
              <div className="mt-4 border-t border-border pt-4">
                <p
                  className="mb-2 text-[11px] uppercase tracking-wide"
                  style={{ color: "var(--text-muted)" }}
                >
                  Statistik turnamen
                </p>
                <div className="flex flex-wrap items-center gap-1.5">
                  {scored.map((c) => {
                    const v = stats![c.key];
                    const icon = statIcon(c.key);
                    return (
                      <span key={c.key} className="pill" title={c.label} aria-label={`${v} ${c.label}`}>
                        {v}
                        {icon ? (
                          <icon.Icon
                            className="h-3.5 w-3.5 shrink-0"
                            style={{ color: icon.color }}
                            fill={icon.filled ? "currentColor" : "none"}
                            aria-hidden="true"
                          />
                        ) : (
                          c.short
                        )}
                      </span>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
