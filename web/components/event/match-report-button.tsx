"use client";

import { useMutation } from "@tanstack/react-query";
import { FileText } from "lucide-react";
import { toast } from "sonner";

import { downloadMatchReport } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";

/**
 * Unduh laporan pertandingan.
 *
 * Sibling of MatchLineupSheetButton, and the split is the point: that sheet is
 * printed *before* kickoff once the referee approves both squads, this report is
 * filed *after* it once there is a result. Two documents, two gates, two
 * buttons — one button that changed meaning halfway through a fixture would be
 * worse than either.
 *
 * **The gate is the server's alone.** It refuses (422) until the match is
 * finished with a scoreline, and the sentence it refuses with is what explains
 * why. The caller hides this until a result exists, which is not that rule — it
 * is the fixture having nothing to report yet, the same distinction the sheet
 * button's caller already draws about seated teams.
 */
export function MatchReportButton({ orgId, matchId }: { orgId: string; matchId: string }) {
  const report = useMutation({
    mutationFn: () => downloadMatchReport(orgId, matchId),
    onError: (err) => toast.error(parseApiError(err, "Gagal mengunduh laporan pertandingan.").message),
  });

  return (
    <Button
      size="sm"
      variant="ghost"
      disabled={report.isPending}
      onClick={() => report.mutate()}
      className="text-muted-foreground"
    >
      <FileText className="h-4 w-4" />
      {report.isPending ? "Menyiapkan…" : "Laporan"}
    </Button>
  );
}
