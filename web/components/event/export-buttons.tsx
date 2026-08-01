"use client";

import { useState } from "react";
import { FileSpreadsheet, FileText } from "lucide-react";
import { toast } from "sonner";

import { downloadExport, type ExportFormat, type ExportKind } from "@/lib/api/exports";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";

/**
 * Excel and PDF downloads for one export.
 *
 * Two buttons rather than a dropdown: there are exactly two formats, and a menu
 * would add a UI primitive this codebase doesn't have for no gain.
 *
 * `enabled` is the event's `export_data` entitlement. When it's off the buttons
 * stay visible but disabled, with a title saying why — hiding them entirely
 * would leave an organizer wondering where the feature went, and the plan they
 * need is per event, not per account.
 */
export function ExportButtons({
  orgId,
  eventId,
  kind,
  categoryId,
  enabled,
  size = "sm",
}: {
  orgId?: string;
  eventId: string;
  kind: ExportKind;
  categoryId?: string;
  enabled: boolean;
  size?: "sm" | "default";
}) {
  const [busy, setBusy] = useState<ExportFormat | null>(null);

  const run = async (format: ExportFormat) => {
    if (!orgId) return;
    setBusy(format);
    try {
      await downloadExport(orgId, eventId, kind, format, categoryId);
    } catch (err) {
      toast.error(parseApiError(err, "Gagal mengunduh export.").message);
    } finally {
      setBusy(null);
    }
  };

  const title = enabled ? undefined : "Export data tidak termasuk dalam paket event ini";

  return (
    <div className="flex gap-2">
      <Button
        variant="outline"
        size={size}
        disabled={!enabled || !orgId || busy !== null}
        title={title}
        onClick={() => run("xlsx")}
      >
        <FileSpreadsheet className="h-4 w-4" />
        {busy === "xlsx" ? "Menyiapkan…" : "Excel"}
      </Button>
      <Button
        variant="outline"
        size={size}
        disabled={!enabled || !orgId || busy !== null}
        title={title}
        onClick={() => run("pdf")}
      >
        <FileText className="h-4 w-4" />
        {busy === "pdf" ? "Menyiapkan…" : "PDF"}
      </Button>
    </div>
  );
}
