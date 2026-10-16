"use client";

import { useCallback, useState } from "react";
import { useParams } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  Camera,
  CameraOff,
  CheckCircle2,
  ScanLine,
  XCircle,
} from "lucide-react";

import { getRaceCheckins, getRaceReport, scanRaceEntry } from "@/lib/api/race";
import { getEvent } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useQrScanner } from "@/lib/hooks/use-qr-scanner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RaceRouteGuard } from "@/components/event/race-route-guard";
import type { RaceScanResponse, RaceScanResult } from "@/types/api";

type Feedback = {
  kind: RaceScanResult;
  message: string;
  name?: string | null;
  category?: string | null;
  bib?: string | null;
};

/**
 * Four results, not the ticket gate's five.
 *
 * There is no `wrong_day`: a race is one morning, so there is no second day to
 * be wrong about — see RaceScanController. Typed against `RaceScanResult`
 * rather than `ScanResult` so this map cannot be handed a result it has no
 * entry for.
 */
const FEEDBACK_STYLE: Record<
  RaceScanResult,
  { icon: typeof CheckCircle2; color: string; title: string }
> = {
  valid: { icon: CheckCircle2, color: "var(--success)", title: "Peserta Valid" },
  used: { icon: XCircle, color: "var(--danger)", title: "Sudah Check-in" },
  unpaid: { icon: AlertTriangle, color: "var(--warning)", title: "Belum Lunas" },
  invalid: { icon: XCircle, color: "var(--danger)", title: "Tidak Ditemukan" },
};

/**
 * The registration desk: scan a runner's QR, hand over their race pack.
 *
 * Its own page rather than a mode on the ticket scanner, mirroring the backend
 * split — and the difference is visible in what is missing here: no
 * whole-order switch (a runner is one person), no date on the result card (one
 * morning), and a running "X dari Y sudah datang" count instead, which is the
 * number a desk actually watches.
 */
export default function RaceScanPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();
  const qc = useQueryClient();

  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [manual, setManual] = useState("");

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const reportQuery = useQuery({
    queryKey: ["race-report", orgId, eventId],
    queryFn: () => getRaceReport(orgId!, eventId),
    enabled: !!orgId,
  });

  const logQuery = useQuery({
    queryKey: ["race-checkins", orgId, eventId],
    queryFn: () => getRaceCheckins(orgId!, eventId, { per_page: 10 }),
    enabled: !!orgId,
  });

  const handleScan = useCallback(
    async (code: string) => {
      if (!orgId) return;

      try {
        const res: RaceScanResponse = await scanRaceEntry(orgId, eventId, code);
        setFeedback({
          kind: res.result,
          message: "Serahkan race pack.",
          name: res.entry?.full_name,
          category: res.entry?.category,
          bib: res.entry?.bib_number,
        });

        // Both, because the desk reads them together: the count above and the
        // name that just joined the list below.
        qc.invalidateQueries({ queryKey: ["race-report", orgId, eventId] });
        qc.invalidateQueries({ queryKey: ["race-checkins", orgId, eventId] });
      } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        const data = (err as {
          response?: {
            data?: {
              errors?: {
                result?: string;
                entry?: { full_name?: string | null; category?: string | null; bib_number?: string | null };
              };
            };
          };
        })?.response?.data;

        setFeedback({
          // 404 is always "not ours", whatever the body says.
          kind: status === 404 ? "invalid" : ((data?.errors?.result as RaceScanResult) ?? "invalid"),
          message: parseApiError(err, "Peserta tidak valid.").message,
          // The refusals carry the runner too: the desk's next question after
          // "sudah check-in" is "who was it?".
          name: data?.errors?.entry?.full_name,
          category: data?.errors?.entry?.category,
          bib: data?.errors?.entry?.bib_number,
        });
      }
    },
    [orgId, eventId, qc]
  );

  // The camera half is shared with the ticket gate — the decode callback is
  // captured once, so the hook holds `onScan` in a ref. See useQrScanner.
  const { videoRef, scanning, cameraError, start, stop, submit } = useQrScanner({
    onScan: handleScan,
  });

  const fb = feedback ? FEEDBACK_STYLE[feedback.kind] : null;
  const checkin = reportQuery.data?.checkin;

  return (
    <div>
      <PageHeader
        title="Check-in Peserta Lomba"
        description="Arahkan kamera ke QR peserta untuk mencatat kehadiran dan menyerahkan race pack."
        backHref={`/organizer/events/${eventId}/race`}
        backLabel="Peserta Lomba"
      />

      <RaceRouteGuard event={eventQuery.data} eventId={eventId} requires="race">
        {/* The desk's running total. First, because it is the number staff look
            up at between runners — and it is the server's count, never summed
            from the log below, which is paginated. */}
        {reportQuery.isLoading ? (
          <Skeleton className="mb-6 h-20 w-full rounded-xl" />
        ) : (
          checkin && (
            <Card className="mb-6 flex flex-wrap items-center justify-between gap-4 p-4">
              <div>
                <p className="text-sm text-muted-foreground">Sudah datang</p>
                <p className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
                  {checkin.checked_in}{" "}
                  <span className="text-base font-normal text-muted-foreground">
                    dari {checkin.total} peserta lunas
                  </span>
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                {reportQuery.data?.categories.map((c) => (
                  <Badge key={c.id} variant="outline">
                    {c.name}: {c.checked_in}/{c.paid}
                  </Badge>
                ))}
              </div>
            </Card>
          )
        )}

        <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
          <Card className="overflow-hidden p-0">
            <div className="relative aspect-video w-full bg-black">
              <video ref={videoRef} className="h-full w-full object-cover" muted playsInline />
              {!scanning && (
                <div className="absolute inset-0 grid place-items-center text-center text-sm text-white/70">
                  <div>
                    <ScanLine className="mx-auto mb-2 h-8 w-8" />
                    Kamera nonaktif
                  </div>
                </div>
              )}
              {scanning && (
                <div className="pointer-events-none absolute inset-0 grid place-items-center">
                  <div className="h-48 w-48 rounded-2xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]" />
                </div>
              )}
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-3 p-4">
              {scanning ? (
                <Button variant="outline" onClick={stop}>
                  <CameraOff className="h-4 w-4" />
                  Hentikan kamera
                </Button>
              ) : (
                <Button onClick={start}>
                  <Camera className="h-4 w-4" />
                  Mulai scan
                </Button>
              )}
            </div>
            {cameraError && (
              <p className="px-4 pb-4 text-sm text-[var(--danger)]">{cameraError}</p>
            )}
          </Card>

          <div className="grid content-start gap-4">
            {fb && feedback ? (
              <Card
                className="p-5 text-center"
                style={{
                  borderColor: fb.color,
                  background: `color-mix(in srgb, ${fb.color} 8%, transparent)`,
                }}
              >
                <fb.icon className="mx-auto h-12 w-12" style={{ color: fb.color }} />
                <h3
                  className="mt-2 text-lg font-bold"
                  style={{ fontFamily: "var(--font-display)", color: fb.color }}
                >
                  {fb.title}
                </h3>
                {feedback.name && <p className="mt-1 text-lg font-medium">{feedback.name}</p>}
                {feedback.category && (
                  <p className="text-sm text-muted-foreground">{feedback.category}</p>
                )}
                {feedback.bib && (
                  <p className="mt-1.5 text-sm font-semibold" style={{ color: fb.color }}>
                    BIB {feedback.bib}
                  </p>
                )}
                {/* One message either way: on a pass it is the instruction,
                    on a refusal it is the server's own reason — which is what
                    the desk has to act on. */}
                <p className="mt-2 text-sm text-muted-foreground">{feedback.message}</p>
              </Card>
            ) : (
              <Card className="grid place-items-center p-8 text-center text-sm text-muted-foreground">
                Hasil scan akan tampil di sini.
              </Card>
            )}

            {/* Manual entry: a cracked lens, a code that will not focus. Goes
                through the hook's `submit` so it is debounced exactly like a
                camera read. */}
            <Card className="p-4">
              <h4 className="mb-2 text-sm font-semibold">Input manual</h4>
              <form
                className="flex gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  const code = manual.trim();
                  if (code) {
                    void submit(code);
                    setManual("");
                  }
                }}
              >
                <Input
                  value={manual}
                  onChange={(e) => setManual(e.target.value)}
                  placeholder="Kode peserta (RUN-…)"
                />
                <Button type="submit" variant="outline">
                  Cek
                </Button>
              </form>
            </Card>

            <Card className="p-4">
              <h4 className="mb-2 text-sm font-semibold">Terakhir check-in</h4>
              {logQuery.isLoading ? (
                <Skeleton className="h-24 w-full" />
              ) : (logQuery.data?.items.length ?? 0) === 0 ? (
                <p className="text-sm text-muted-foreground">Belum ada yang datang.</p>
              ) : (
                <ul className="divide-y divide-border text-sm">
                  {logQuery.data?.items.map((e) => (
                    <li key={e.id} className="flex items-baseline justify-between gap-3 py-2">
                      <span className="min-w-0">
                        <span className="block truncate font-medium">{e.full_name}</span>
                        <span className="text-xs text-muted-foreground">{e.category ?? "—"}</span>
                      </span>
                      <span className="shrink-0 text-xs text-muted-foreground">
                        {e.checked_in_at
                          ? new Date(e.checked_in_at).toLocaleTimeString("id-ID", {
                              hour: "2-digit",
                              minute: "2-digit",
                            })
                          : "—"}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
      </RaceRouteGuard>
    </div>
  );
}
