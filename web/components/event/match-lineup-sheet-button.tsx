"use client";

import { useMutation } from "@tanstack/react-query";
import { Printer } from "lucide-react";
import { toast } from "sonner";

import { downloadLineupSheet } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";

/**
 * Cetak susunan pemain untuk meja IP.
 *
 * A mutation rather than a query: it writes nothing, but it is fired by a click
 * and its whole result is a file plus, on a refusal, a message — the shape
 * `useQuery` is worst at.
 *
 * **The gate is the server's alone.** It refuses (422) until the referee has
 * signed off *both* sides, and the sentence it refuses with is the only thing
 * that says which half is still missing. Hiding or disabling this button until
 * the client thinks both are approved would make it a second reader of that
 * rule, and the two would disagree the first time a sheet was approved in
 * another tab. So the button is always offered and the refusal is the answer —
 * the same reasoning as the staff's copy on `/officiating`.
 */
export function MatchLineupSheetButton({ orgId, matchId }: { orgId: string; matchId: string }) {
  const sheet = useMutation({
    mutationFn: () => downloadLineupSheet(orgId, matchId),
    onError: (err) => toast.error(parseApiError(err, "Gagal mengunduh susunan pemain.").message),
  });

  return (
    <Button
      size="sm"
      variant="ghost"
      disabled={sheet.isPending}
      onClick={() => sheet.mutate()}
      className="text-muted-foreground"
    >
      <Printer className="h-4 w-4" />
      {sheet.isPending ? "Menyiapkan…" : "Susunan pemain"}
    </Button>
  );
}
