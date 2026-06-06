"use client";

import { useEffect, useState } from "react";

const TARGET = new Date("2026-06-14T15:00:00+07:00").getTime();
const pad = (n: number) => String(n).padStart(2, "0");

function parts(diff: number) {
  if (diff < 0) diff = 0;
  return {
    d: pad(Math.floor(diff / 86400000)),
    h: pad(Math.floor((diff % 86400000) / 3600000)),
    m: pad(Math.floor((diff % 3600000) / 60000)),
    s: pad(Math.floor((diff % 60000) / 1000)),
  };
}

export function Countdown() {
  // Render a stable "00" placeholder on the server (matching the reference),
  // then start ticking on mount to avoid a hydration mismatch.
  const [t, setT] = useState({ d: "00", h: "00", m: "00", s: "00" });

  useEffect(() => {
    const tick = () => setT(parts(TARGET - Date.now()));
    tick();
    const id = setInterval(tick, 1000);
    return () => clearInterval(id);
  }, []);

  return (
    <div className="countdown">
      <div className="countdown-label">Kick-off dalam</div>
      <div className="cd-grid">
        <div className="cd-cell">
          <div className="cd-num">{t.d}</div>
          <div className="cd-unit">Hari</div>
        </div>
        <div className="cd-cell">
          <div className="cd-num">{t.h}</div>
          <div className="cd-unit">Jam</div>
        </div>
        <div className="cd-cell">
          <div className="cd-num">{t.m}</div>
          <div className="cd-unit">Menit</div>
        </div>
        <div className="cd-cell">
          <div className="cd-num">{t.s}</div>
          <div className="cd-unit">Detik</div>
        </div>
      </div>
      <div className="countdown-foot">
        <span className="badge badge-live">
          <span className="dot" /> Pendaftaran tutup 8 Juni
        </span>
      </div>
    </div>
  );
}
