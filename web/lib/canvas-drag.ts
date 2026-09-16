"use client";

import { useCallback, useEffect, useRef, useState, type RefObject } from "react";

/**
 * The pointer plumbing shared by the certificate and ID card editors.
 *
 * What is shared here is deliberately only the part that has no geometry in it:
 * listen on `window` (not on the dragged element, or the drag dies the moment
 * the pointer outruns it), translate a client point into a percentage of the
 * canvas, and stop on pointerup. Everything the two editors disagree about —
 * what the percentage *means*, whether there is a box to resize, which anchor a
 * field hangs from — stays in each editor, because those are two different
 * coordinate systems rather than two configurations of one.
 */

/** Percentages of the canvas, clamped and rounded. */
export type CanvasPoint = { x: number; y: number };

/** 0–100, one decimal — the same shape the backend validates against. */
export const clampPercent = (value: number): number =>
  Math.round(Math.min(100, Math.max(0, value)) * 10) / 10;

/**
 * Drag state for a canvas.
 *
 * `T` is whatever the editor needs to know about the drag in progress — a field
 * index, or an index plus which handle was grabbed. It lives in a ref rather
 * than in state so a pointermove never waits for a render.
 *
 * @param canvasRef the element percentages are measured against
 * @param onMove    called on every pointermove while a drag is live
 */
export function useCanvasDrag<T>(
  canvasRef: RefObject<HTMLElement | null>,
  onMove: (payload: T, point: CanvasPoint) => void,
) {
  const draggingRef = useRef<T | null>(null);

  // The latest handler, so the window listeners below are installed once and
  // still see fresh props. Re-subscribing on every render would drop whatever
  // moved during the gap between removeEventListener and addEventListener.
  const moveRef = useRef(onMove);
  useEffect(() => {
    moveRef.current = onMove;
  }, [onMove]);

  const start = useCallback((payload: T) => {
    draggingRef.current = payload;
  }, []);

  useEffect(() => {
    const handleMove = (e: PointerEvent) => {
      const payload = draggingRef.current;
      if (payload === null) return;

      const rect = canvasRef.current?.getBoundingClientRect();
      if (!rect || rect.width === 0 || rect.height === 0) return;

      // Without this the browser turns the drag into a text selection, which on
      // a canvas full of sample text is every drag.
      e.preventDefault();

      moveRef.current(payload, {
        x: clampPercent(((e.clientX - rect.left) / rect.width) * 100),
        y: clampPercent(((e.clientY - rect.top) / rect.height) * 100),
      });
    };

    const stop = () => {
      draggingRef.current = null;
    };

    window.addEventListener("pointermove", handleMove);
    window.addEventListener("pointerup", stop);
    // pointercancel fires when the browser takes the pointer away — a touch
    // turning into a scroll. Without it the field stays glued to the finger.
    window.addEventListener("pointercancel", stop);

    return () => {
      window.removeEventListener("pointermove", handleMove);
      window.removeEventListener("pointerup", stop);
      window.removeEventListener("pointercancel", stop);
    };
  }, [canvasRef]);

  return { start };
}

/**
 * The canvas's rendered width, tracked through a ResizeObserver.
 *
 * Both editors need it for the same reason: font sizes are stored in a print
 * unit (points there, millimetres here) and have to be scaled to whatever width
 * the canvas actually got, or the preview stops matching the output.
 */
export function useCanvasWidth(canvasRef: RefObject<HTMLElement | null>): number {
  const [width, setWidth] = useState(0);

  useEffect(() => {
    const el = canvasRef.current;
    if (!el) return;

    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
    observer.observe(el);
    return () => observer.disconnect();
  }, [canvasRef]);

  return width;
}
