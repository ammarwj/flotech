"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { BrowserMultiFormatReader, type IScannerControls } from "@zxing/browser";

/**
 * A camera that reads QR codes and hands each one to a callback exactly once.
 *
 * Extracted when the fun run desk became the second scanner: the ticket gate
 * and the registration desk ask completely different questions of the server,
 * but the camera half is identical down to the pitfalls — and those pitfalls
 * are not obvious, which is the argument for one copy of them:
 *
 * - **The decode callback is captured once** by `decodeFromVideoDevice`, so
 *   anything it reads from React state is frozen at the moment scanning began.
 *   `onScan` is mirrored into a ref for that reason; passing it as a dependency
 *   instead would restart the camera mid-queue every time the parent
 *   re-rendered.
 * - **A camera reads the same code many times a second.** Without the debounce
 *   below, one code held in frame fires dozens of requests and the desk's
 *   second scan is answered "sudah check-in" by its own first.
 * - **`busy` is a ref, not state.** The callback fires faster than React
 *   commits, so a state flag would let several requests through before the
 *   first render that disables it.
 *
 * What it deliberately does NOT do is interpret the result. Each caller owns
 * its own endpoint, its own refusals and its own feedback card — that is where
 * the two scanners genuinely differ.
 */
export function useQrScanner({
  onScan,
  /** How long the same code is ignored after being read, in ms. */
  debounceMs = 3000,
}: {
  onScan: (code: string) => void | Promise<void>;
  debounceMs?: number;
}) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const controlsRef = useRef<IScannerControls | null>(null);
  const busyRef = useRef(false);
  const lastCodeRef = useRef<{ code: string; at: number } | null>(null);
  const onScanRef = useRef(onScan);

  // Kept current without re-creating `start`, which would restart the camera.
  useEffect(() => {
    onScanRef.current = onScan;
  }, [onScan]);

  const [scanning, setScanning] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);

  /**
   * Feed one code in. Exposed as well as used internally, because every
   * scanner needs a manual fallback — a cracked lens, a code that will not
   * focus — and that path has to be debounced the same way.
   */
  const submit = useCallback(
    async (code: string) => {
      if (busyRef.current) return;

      const now = Date.now();
      const last = lastCodeRef.current;
      if (last && last.code === code && now - last.at < debounceMs) return;
      lastCodeRef.current = { code, at: now };

      busyRef.current = true;
      try {
        await onScanRef.current(code);
      } finally {
        busyRef.current = false;
      }
    },
    [debounceMs]
  );

  const start = useCallback(async () => {
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
      setCameraError(
        "Tidak bisa mengakses kamera. Izinkan akses kamera atau gunakan input manual."
      );
      setScanning(false);
    }
  }, [submit]);

  const stop = useCallback(() => {
    controlsRef.current?.stop();
    controlsRef.current = null;
    setScanning(false);
  }, []);

  // The camera keeps running after unmount otherwise — the device light stays
  // on and the page that navigated away is still decoding frames.
  useEffect(() => () => controlsRef.current?.stop(), []);

  return { videoRef, scanning, cameraError, start, stop, submit };
}
