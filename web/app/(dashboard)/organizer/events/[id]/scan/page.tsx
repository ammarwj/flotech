"use client";

import { useCallback, useRef, useState } from "react";
import { useParams } from "next/navigation";
import {
  CheckCircle2,
  XCircle,
  AlertTriangle,
  CalendarX,
  Camera,
  CameraOff,
  ScanLine,
} from "lucide-react";

import { scanTicket } from "@/lib/api/tickets";
import { parseApiError } from "@/lib/api/errors";
import { useQuery } from "@tanstack/react-query";
import { getEvent } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useQrScanner } from "@/lib/hooks/use-qr-scanner";
import { dayFullLabel } from "@/lib/match-dates";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { PageHeader } from "@/components/shared/page-header";
import { RaceRouteGuard } from "@/components/event/race-route-guard";
import type { ScanResponse, ScanResult } from "@/types/api";

type Feedback = {
  kind: ScanResult;
  message: string;
  holder?: string | null;
  category?: string | null;
  /** The day this ticket admits its holder; null when it has none. */
  eventDate?: string | null;
  /**
   * How many tickets the scan admitted, and the order it belongs to counted
   * for today. Both come from the server — the gate reads "20 dari 20" because
   * it was counted, not because this page multiplied anything.
   *
   * Only ever set on a `valid` result: the refusals write nothing.
   */
  checkedIn?: number;
  orderCheckedIn?: number;
  orderTotal?: number;
};

const FEEDBACK_STYLE: Record<
  Feedback["kind"],
  { icon: typeof CheckCircle2; color: string; title: string }
> = {
  valid: { icon: CheckCircle2, color: "var(--success)", title: "Tiket Valid" },
  used: { icon: XCircle, color: "var(--danger)", title: "Sudah Digunakan" },
  unpaid: { icon: AlertTriangle, color: "var(--warning)", title: "Belum Dibayar" },
  invalid: { icon: XCircle, color: "var(--danger)", title: "Tiket Tidak Valid" },
  // A warning, not a danger: the ticket is genuine and paid, it is just not
  // today's. The holder comes back tomorrow rather than being turned away.
  wrong_day: { icon: CalendarX, color: "var(--warning)", title: "Hari Tidak Sesuai" },
};

export default function ScanPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();

  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [manual, setManual] = useState("");

  // Loaded for the guard alone: this gate reads `tickets`, and a fun run's
  // codes are `race_entries` rows the other desk owns. Shares the `["event",…]`
  // key with every other page, so arriving from the ticket page costs nothing.
  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });
  /**
   * Gate mode: one scan admits the scanned ticket's whole order. A party of 20
   * arrives together holding one phone, and reading 20 codes off it is the
   * slowest way to let in people already standing in front of you.
   *
   * Mirrored into a ref because the camera's decode callback is captured once
   * by `startCamera()` — reading the state there would admit a single ticket
   * for the rest of the session no matter what the staff toggled afterwards.
   * `submit` therefore does not take the mode as a dependency either, so
   * flipping the switch never restarts the camera mid-queue.
   */
  const [wholeOrder, setWholeOrder] = useState(false);
  const wholeOrderRef = useRef(false);

  const toggleWholeOrder = useCallback((next: boolean) => {
    wholeOrderRef.current = next;
    setWholeOrder(next);
  }, []);

  /**
   * What this gate does with a code. The camera, the debounce and the busy
   * guard are the shared hook's — see useQrScanner, which also explains why
   * `wholeOrderRef` is still a ref here.
   */
  const handleScan = useCallback(
    async (qr: string) => {
      if (!orgId) return;

      try {
        const res: ScanResponse = await scanTicket(orgId, eventId, qr, wholeOrderRef.current);
        const admitted = res.checked_in ?? 1;
        setFeedback({
          kind: res.result,
          // The count is the message when it is more than one: the staff's
          // only way to tell a bulk scan worked is being told how many it let
          // in, and "persilakan masuk" above a party of 20 reads as one.
          message:
            admitted > 1 ? `${admitted} tiket di-check-in.` : "Persilakan masuk.",
          holder: res.ticket?.holder_name,
          category: res.ticket?.category,
          eventDate: res.ticket?.event_date,
          checkedIn: admitted,
          orderCheckedIn: res.order?.checked_in,
          orderTotal: res.order?.total,
        });
      } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        const data = (err as {
          response?: {
            data?: {
              errors?: { result?: string; ticket?: { holder_name?: string | null; category?: string | null; event_date?: string | null } };
              message?: string;
            };
          };
        })?.response?.data;
        const kind = (data?.errors?.result as Feedback["kind"]) ?? "invalid";
        const parsed = parseApiError(err, "Tiket tidak valid.");
        setFeedback({
          kind: status === 404 ? "invalid" : kind,
          message: parsed.message,
          // The refusals carry the ticket too, and the gate's next question
          // after "salah hari" is "then which day?" — so the holder and the
          // date are rendered on every result, not only the valid one.
          holder: data?.errors?.ticket?.holder_name,
          category: data?.errors?.ticket?.category,
          eventDate: data?.errors?.ticket?.event_date,
        });
      }
    },
    [orgId, eventId]
  );

  const {
    videoRef,
    scanning,
    cameraError,
    start: startCamera,
    stop: stopCamera,
    submit,
  } = useQrScanner({ onScan: handleScan });

  const fb = feedback ? FEEDBACK_STYLE[feedback.kind] : null;

  return (
    <div>
      <PageHeader
        title="Scan Check-in"
        description="Arahkan kamera ke QR Code tiket penonton untuk validasi masuk. Untuk rombongan, nyalakan check-in seluruh pesanan."
        backHref={`/organizer/events/${eventId}/tickets`}
        backLabel="Kelola tiket"
      />

      <RaceRouteGuard event={eventQuery.data} eventId={eventId} requires="competition">

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
              <Button variant="outline" onClick={stopCamera}>
                <CameraOff className="h-4 w-4" />
                Hentikan kamera
              </Button>
            ) : (
              <Button onClick={startCamera}>
                <Camera className="h-4 w-4" />
                Mulai scan
              </Button>
            )}

            {/* Next to the camera button, not in the result card: it is a mode
                the staff sets before the queue starts moving, and it stays on
                until they turn it off. Switchable while scanning — see the ref
                in `wholeOrder`. */}
            <label
              htmlFor="whole-order"
              className="flex cursor-pointer items-center gap-2.5 text-sm"
            >
              <Switch
                id="whole-order"
                checked={wholeOrder}
                onCheckedChange={toggleWholeOrder}
              />
              <span>
                <span className="font-medium">Check-in seluruh pesanan</span>
                <span className="block text-xs text-muted-foreground">
                  Satu scan masukkan semua tiket pesanan itu untuk hari ini.
                </span>
              </span>
            </label>
          </div>
          {cameraError && (
            <p className="px-4 pb-4 text-sm text-[var(--danger)]">{cameraError}</p>
          )}
        </Card>

        <div className="grid gap-4 content-start">
          {/* Result */}
          {fb && feedback ? (
            <Card
              className="p-5 text-center"
              style={{ borderColor: fb.color, background: `color-mix(in srgb, ${fb.color} 8%, transparent)` }}
            >
              <fb.icon className="mx-auto h-12 w-12" style={{ color: fb.color }} />
              <h3 className="mt-2 text-lg font-bold" style={{ fontFamily: "var(--font-display)", color: fb.color }}>
                {/* A bulk scan says how many it let in, in the place the staff
                    already looks. Falls back to the per-result title whenever
                    the count is 1 or absent (every refusal), so this is one
                    extra word on the happy path, not a second kind of card. */}
                {(feedback.checkedIn ?? 1) > 1
                  ? `${feedback.checkedIn} Tiket Masuk`
                  : fb.title}
              </h3>
              {feedback.holder && <p className="mt-1 font-medium">{feedback.holder}</p>}
              {feedback.category && (
                <p className="text-sm text-muted-foreground">{feedback.category}</p>
              )}
              {feedback.eventDate && (
                <p className="mt-1.5 text-sm font-semibold" style={{ color: fb.color }}>
                  Berlaku {dayFullLabel(feedback.eventDate)}
                </p>
              )}
              <p className="mt-2 text-sm text-muted-foreground">{feedback.message}</p>
              {/* Only for a multi-seat order: "1 dari 1" under a lone visitor
                  is noise. The staff's question after "20 masuk" is "out of how
                  many?", and a party arriving in two groups needs the second
                  scan to read 20 dari 20. */}
              {typeof feedback.orderTotal === "number" && feedback.orderTotal > 1 && (
                <p className="mt-1 text-xs text-muted-foreground">
                  Pesanan ini: {feedback.orderCheckedIn} dari {feedback.orderTotal} tiket
                  sudah check-in hari ini.
                </p>
              )}
            </Card>
          ) : (
            <Card className="grid place-items-center p-8 text-center text-sm text-muted-foreground">
              Hasil scan akan tampil di sini.
            </Card>
          )}

          {/* Manual entry fallback */}
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
                placeholder="Kode tiket (TIX-…)"
              />
              <Button type="submit" variant="outline">
                Cek
              </Button>
            </form>
          </Card>
        </div>
      </div>
      </RaceRouteGuard>
    </div>
  );
}
