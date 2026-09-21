"use client";

import { rupiah } from "@/lib/labels";
import type { DepositTeam } from "@/types/api";

/**
 * Every team's jaminan balance, purely derived: no row here is ever stored,
 * so re-typing a match's cards (organizer corrects 3 yellows down to 2)
 * changes what this table shows on the very next load. See DepositService.
 */
export function DepositTable({
  teams,
  amount,
  yellowDeduction,
  redDeduction,
}: {
  teams: DepositTeam[];
  amount: number;
  yellowDeduction: number;
  redDeduction: number;
}) {
  if (teams.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">Belum ada tim terdaftar di event ini.</p>
    );
  }

  return (
    <div className="grid gap-3">
      <div className="overflow-x-auto rounded-xl border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
              <th className="px-3 py-3 text-left font-semibold">Tim</th>
              <th className="hidden px-3 py-3 text-left font-semibold sm:table-cell">Kategori</th>
              <th className="px-2 py-3 text-center font-semibold">KK</th>
              <th className="px-2 py-3 text-center font-semibold">KM</th>
              <th className="px-3 py-3 text-right font-semibold">Potongan</th>
              <th className="px-3 py-3 text-right font-semibold">Sisa Saldo</th>
            </tr>
          </thead>
          <tbody>
            {teams.map((t) => (
              <tr key={t.team_id} className="border-t border-border">
                <td className="px-3 py-3">
                  <div className="font-semibold">{t.team_name}</div>
                  <div className="text-xs text-muted-foreground sm:hidden">{t.category_name}</div>
                </td>
                <td className="hidden px-3 py-3 text-muted-foreground sm:table-cell">
                  {t.category_name}
                </td>
                <td className="px-2 py-3 text-center font-semibold">{t.yellow_count}</td>
                <td className="px-2 py-3 text-center font-semibold">{t.red_count}</td>
                <td className="px-3 py-3 text-right text-muted-foreground">
                  {t.deduction > 0 ? `-${rupiah(t.deduction)}` : rupiah(0)}
                </td>
                <td
                  className={`px-3 py-3 text-right font-semibold ${
                    t.balance < 0 ? "text-[var(--danger)]" : ""
                  }`}
                >
                  {rupiah(t.balance)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-xs text-muted-foreground">
        Jaminan awal {rupiah(amount)} per tim · potongan kartu kuning {rupiah(yellowDeduction)},
        kartu merah {rupiah(redDeduction)}. Saldo dihitung ulang dari kartu yang tercatat di
        pertandingan yang sudah selesai dan dikonfirmasi.
      </p>
    </div>
  );
}
