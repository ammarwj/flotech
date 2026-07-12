"use client";

import { StandingsTable } from "@/components/event/standings-table";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { HybridConfig } from "@/lib/hybrid";
import type { Standing } from "@/types/api";

/**
 * One table per group, with the automatic qualifying places highlighted. Extra
 * qualifiers (best runner-up / best third) are decided across groups, so they
 * aren't marked inside a single table.
 */
export function GroupStandings({
  standings,
  config,
}: {
  standings: Standing[];
  config: HybridConfig;
}) {
  const { tiebreakerLabel } = useCatalog();
  const groups = new Map<string, Standing[]>();
  for (const s of standings) {
    const key = s.group_name ?? "-";
    groups.set(key, [...(groups.get(key) ?? []), s]);
  }

  if (groups.size === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Klasemen muncul setelah tim diundi ke grup dan ada hasil pertandingan.
      </p>
    );
  }

  const names = [...groups.keys()].sort();

  return (
    // Tables sit side by side on a wide screen instead of one long column.
    <div className="grid gap-6 lg:grid-cols-2 2xl:grid-cols-3">
      {names.map((name) => (
        <section key={name} className="min-w-0">
          <h3 className="mb-3 text-sm font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {name === "-" ? "Belum diundi" : `Grup ${name}`}
          </h3>
          <StandingsTable
            standings={groups.get(name)!}
            highlight={name === "-" ? 0 : config.qualification.top_per_group}
          />
        </section>
      ))}

      <p className="text-xs text-muted-foreground lg:col-span-full">
        Baris hijau = lolos otomatis ke knockout. Tie breaker:{" "}
        {config.tiebreakers.map(tiebreakerLabel).join(" → ")}.
      </p>
    </div>
  );
}
