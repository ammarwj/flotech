"use client";

import { useState } from "react";
import { ArrowDown, Download } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/select";
import { crestGradient } from "@/lib/bracket";
import { downloadCsv, slugifyFileName, toCsv } from "@/lib/csv";
import { cn } from "@/lib/utils";
import type { Leaderboard } from "@/types/api";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

/**
 * Sport-aware player leaderboard with dynamic stat columns.
 *
 * Sortable by any stat (top scorer, most assists, most cards…) — click a column
 * header or use the picker — and exportable as a spreadsheet.
 */
export function LeaderboardTable({
  leaderboard,
  eventName,
}: {
  leaderboard: Leaderboard;
  /** Names the exported file. Without it, the export button is hidden. */
  eventName?: string;
}) {
  const { columns, primary, rows } = leaderboard;
  const [sortKey, setSortKey] = useState(primary);

  if (rows.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Belum ada statistik pemain. Tambahkan dari hasil pertandingan.
      </p>
    );
  }

  const sortColumn = columns.find((c) => c.key === sortKey) ?? columns[0];

  // Ties fall back to the primary stat, then the name, so the order is stable.
  const sorted = [...rows].sort(
    (a, b) =>
      (b.stats[sortKey] ?? 0) - (a.stats[sortKey] ?? 0) ||
      (b.stats[primary] ?? 0) - (a.stats[primary] ?? 0) ||
      a.player_name.localeCompare(b.player_name)
  );

  const exportCsv = () => {
    const csv = toCsv(
      ["Peringkat", "No.", "Pemain", "Tim", ...columns.map((c) => c.label)],
      sorted.map((r, i) => [
        i + 1,
        r.jersey_number ?? "",
        r.player_name,
        r.team_name,
        ...columns.map((c) => r.stats[c.key] ?? 0),
      ])
    );

    downloadCsv(`statistik-pemain-${slugifyFileName(eventName ?? "event")}`, csv);
  };

  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <label htmlFor="sort" className="text-xs font-semibold text-muted-foreground">
          Urutkan
        </label>
        <Select
          id="sort"
          value={sortKey}
          onChange={(e) => setSortKey(e.target.value)}
          className="h-9 w-48"
        >
          {columns.map((c) => (
            <option key={c.key} value={c.key}>
              {c.label} terbanyak
            </option>
          ))}
        </Select>

        {eventName && (
          <Button variant="outline" size="sm" className="ml-auto" onClick={exportCsv}>
            <Download className="h-4 w-4" />
            Export Excel
          </Button>
        )}
      </div>

      <div className="overflow-x-auto rounded-xl border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
              <th className="w-10 px-2 py-3 text-center font-semibold">#</th>
              <th className="px-3 py-3 text-left font-semibold">Pemain</th>
              <th className="hidden px-3 py-3 text-left font-semibold sm:table-cell">Tim</th>
              {columns.map((c) => (
                <th key={c.key} className="px-2 py-3 text-center font-semibold">
                  <button
                    type="button"
                    onClick={() => setSortKey(c.key)}
                    title={`Urutkan berdasarkan ${c.label}`}
                    className={cn(
                      "inline-flex items-center gap-1 transition-colors hover:text-foreground",
                      c.key === sortKey && "text-foreground"
                    )}
                  >
                    {c.short}
                    {c.key === sortKey && <ArrowDown className="h-3 w-3" />}
                  </button>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sorted.map((r, i) => (
              <tr key={r.player_id} className="border-t border-border">
                <td className="px-2 py-3 text-center font-mono text-muted-foreground">{i + 1}</td>
                <td className="px-3 py-3">
                  <div className="flex items-center gap-2.5">
                    <span
                      className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white"
                      style={{ background: crestGradient(r.player_name) }}
                    >
                      {initials(r.player_name)}
                    </span>
                    <div className="min-w-0">
                      <div className="truncate font-semibold">
                        {r.jersey_number && (
                          <span className="mr-1.5 font-mono text-xs font-normal text-muted-foreground">
                            #{r.jersey_number}
                          </span>
                        )}
                        {r.player_name}
                      </div>
                      <div className="truncate text-xs text-muted-foreground sm:hidden">
                        {r.team_name}
                      </div>
                    </div>
                  </div>
                </td>
                <td className="hidden px-3 py-3 text-muted-foreground sm:table-cell">
                  {r.team_name}
                </td>
                {columns.map((c) => (
                  <td
                    key={c.key}
                    className={cn(
                      "px-2 py-3 text-center",
                      c.key === sortKey ? "font-extrabold" : "text-muted-foreground"
                    )}
                    style={c.key === sortKey ? { fontFamily: "var(--font-display)" } : undefined}
                  >
                    {r.stats[c.key] ?? 0}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-xs text-muted-foreground">
        Diurutkan berdasarkan {sortColumn?.label.toLowerCase()} terbanyak · {sorted.length} pemain.
      </p>
    </div>
  );
}
