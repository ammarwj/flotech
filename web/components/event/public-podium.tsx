"use client";

import { crestGradient } from "@/lib/bracket";
import type { CategoryPodium, PodiumPlace } from "@/types/api";

/**
 * What each place is called, and the token prefix that colours it.
 *
 * Numerals rather than 🥇🥈🥉: the emoji render as three different glyphs
 * across platforms and as nothing at all on some Android builds, and the one
 * place they are indispensable — telling a screen reader which medal this is —
 * is served by `label` instead.
 */
const MEDALS: Record<PodiumPlace["place"], { label: string; tone: string }> = {
  1: { label: "Juara 1", tone: "gold" },
  2: { label: "Juara 2", tone: "silver" },
  3: { label: "Juara 3", tone: "bronze" },
};

/** Team crest: real logo when uploaded, gradient fallback otherwise. */
function Crest({
  name,
  logoUrl,
}: {
  name: string;
  logoUrl: string | null | undefined;
}) {
  if (logoUrl) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        className="pod-crest"
        src={logoUrl}
        alt=""
        style={{ objectFit: "cover" }}
      />
    );
  }
  return (
    <span className="pod-crest" style={{ background: crestGradient(name) }} />
  );
}

/**
 * One category's result: the champion stated, the other two listed under it.
 *
 * The champion is the answer to the question this tab exists for, so it is the
 * only thing here given any weight — a larger crest, the name at display size,
 * the one tinted surface. Runner-up and third sit below as a plain list.
 *
 * The earlier version gave all three the same card, the same crest and the
 * same tinted row, which made the podium read as a three-item menu where the
 * colour of a numeral was the only thing saying who actually won. Three equal
 * rows is what a *table* looks like, and the Klasemen tab is already the
 * table.
 *
 * Never padded to three: a bracket with no third-place tie has two, and
 * inventing a bronze would name a result nobody played — see PodiumService.
 */
function CategoryBlock({ category }: { category: CategoryPodium }) {
  const [champion, ...rest] = category.podium;

  if (!champion) {
    return null;
  }

  return (
    <article className="pod-cat">
      <h3 className="pod-cat-name">{category.name}</h3>

      <div className="pod-champ">
        <Crest name={champion.team.name} logoUrl={champion.team.logo_url} />

        <div className="pod-champ-body">
          <span className="pod-champ-label">{MEDALS[1].label}</span>
          <strong className="pod-champ-name">{champion.team.name}</strong>
        </div>
      </div>

      {rest.length > 0 && (
        <ol className="pod-rest">
          {rest.map((p) => (
            <li key={p.place} className={`pod-row pod-row--${MEDALS[p.place].tone}`}>
              {/* Decorative: the place is already spelled out for assistive
                  tech by the label beside it, and read twice it becomes
                  "2 Juara 2 SMK Madani". */}
              <span className="pod-place" aria-hidden>
                {p.place}
              </span>
              <span className="pod-row-label">{MEDALS[p.place].label}</span>
              <span className="pod-team">{p.team.name}</span>
            </li>
          ))}
        </ol>
      )}
    </article>
  );
}

/**
 * The "Juara" tab: every finished category of the event, side by side.
 *
 * All categories at once rather than one behind a chip picker, unlike Bracket
 * and Klasemen. At the end of a tournament "siapa juaranya" is asked of the
 * whole event, and a result is three lines — making someone click through
 * categories to assemble the answer would charge a page of navigation for what
 * fits on one screen.
 *
 * Renders nothing when handed nothing. The tab is hidden in that case anyway,
 * but a component that paints an empty shell would make the hiding rule the
 * only thing standing between a running event and a page announcing that
 * nobody has won — the same call as `Proof()` returning null.
 */
export function PublicPodium({ categories }: { categories: CategoryPodium[] }) {
  if (categories.length === 0) {
    return null;
  }

  return (
    <div className="pod">
      <h2 className="pod-title">Juara</h2>

      <div className="pod-grid">
        {categories.map((c) => (
          <CategoryBlock key={c.id} category={c} />
        ))}
      </div>
    </div>
  );
}
