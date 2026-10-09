"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import { BrowserMultiFormatReader, type IScannerControls } from "@zxing/browser";
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
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { dayFullLabel } from "@/lib/match-dates";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/shared/page-header";
import type { ScanResponse, ScanResult } from "@/types/api";

type Feedback = {
  kind: ScanResult;
  message: string;
  holder?: string | null;
  category?: string | null;
  /** The day this ticket admits its holder; null when it has none. */
  eventDate?: string | null;
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

  const videoRef = useRef<HTMLVideoElement>(null);
  const controlsRef = useRef<IScannerControls | null>(null);
  const busyRef = useRef(false);
  const lastCodeRef = useRef<{ code: string; at: number } | null>(null);

  const [scanning, setScanning] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [manual, setManual] = useState("");

  const submit = useCallback(
    async (qr: string) => {
      if (!orgId || busyRef.current) return;

      // Debounce repeat reads of the same code within 3s.
      const now = Date.now();
      const last = lastCodeRef.current;
      if (last && last.code === qr && now - last.at < 3000) return;
      lastCodeRef.current = { code: qr, at: now };

      busyRef.current = true;
      try {
        const res: ScanResponse = await scanTicket(orgId, eventId, qr);
        setFeedback({
          kind: res.result,
          message: "Persilakan masuk.",
          holder: res.ticket?.holder_name,
          category: res.ticket?.category,
          eventDate: res.ticket?.event_date,
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
      } finally {
        busyRef.current = false;
      }
    },
    [orgId, eventId]
  );

  const startCamera = useCallback(async () => {
    setCameraError(null);
    setScanning(true);
    try {
      const reader = new BrowserMultiFormatReader();
      controlsRef.current = await reader.decodeFromVideoDevice(
        undefined,
        videoRef.current!,
        (result) => {
          if (result) void submit(result.getText());
        }
      );
    } catch {
      setCameraError("Tidak bisa mengakses kamera. Izinkan akses kamera atau gunakan input manual.");
      setScanning(false);
    }
  }, [submit]);

  const stopCamera = useCallback(() => {
    controlsRef.current?.stop();
    controlsRef.current = null;
    setScanning(false);
  }, []);

  useEffect(() => () => controlsRef.current?.stop(), []);

  const fb = feedback ? FEEDBACK_STYLE[feedback.kind] : null;

  return (
    <div>
      <PageHeader
        title="Scan Check-in"
        description="Arahkan kamera ke QR Code tiket penonton untuk validasi masuk."
        backHref={`/organizer/events/${eventId}/tickets`}
        backLabel="Kelola tiket"
      />

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
          <div className="flex items-center gap-2 p-4">
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
                {fb.title}
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
    </div>
  );
}
