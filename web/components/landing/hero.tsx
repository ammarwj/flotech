"use client";

import Link from "next/link";

import { usePublicCta } from "@/components/auth/public-auth-actions";
import { ArrowRight, CheckIcon, StarIcon } from "./icons";

export function Hero() {
  const cta = usePublicCta();

  return (
    <section className="hero">
      <div className="container hero-grid">
        <div>
          <span className="pill">
            <StarIcon width={14} height={14} />
            Platform turnamen olahraga #1 untuk organizer
          </span>
          <h1>
            Atur Turnamen,
            <br />
            <span className="accent">Tanpa Batas.</span>
          </h1>
          <p className="hero-lede">
            Dari registrasi tim, jadwal otomatis, klasemen real-time, tiket QR, sampai sertifikat juara —
            kelola seluruh siklus turnamen olahraga dalam satu platform. Mulai gratis dalam 10 menit.
          </p>
          <div className="hero-cta">
            <Link href={cta.href} className="btn btn-primary btn-lg">
              {cta.label}
              <ArrowRight />
            </Link>
            <Link href="/event" className="btn btn-secondary btn-lg">
              Jelajahi Event
            </Link>
          </div>
          <div className="hero-meta">
            <span>
              <CheckIcon /> Tanpa kartu kredit
            </span>
            <span>
              <CheckIcon /> Setup 10 menit
            </span>
            <span>
              <CheckIcon /> 5 cabang olahraga
            </span>
          </div>
        </div>

        {/* product mockup */}
        <div className="reveal in mock-wrap">
          <div className="mock">
            <div className="mock-bar">
              <span className="mock-dot" />
              <span className="mock-dot" />
              <span className="mock-dot" />
              <span className="mock-url">flo-event.id/jakarta-cup/liga-2026</span>
            </div>
            <div className="mock-body">
              <div className="mock-head">
                <div className="mock-evt">
                  <span className="mock-evt-logo">
                    <svg viewBox="0 0 24 24" fill="none">
                      <path
                        d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 4 2.9 2.1-1.1 3.4h-3.6L9.1 8.1 12 6Z"
                        stroke="#fff"
                        strokeWidth="1.6"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </span>
                  <div>
                    <b>Jakarta Cup 2026</b>
                    <small>Liga · 16 Tim · Sepak Bola</small>
                  </div>
                </div>
                <span className="badge badge-live">
                  <span className="dot" /> LIVE
                </span>
              </div>

              <div className="mock-live">
                <div className="mock-live-top">
                  <span className="badge badge-live">
                    <span className="dot" /> Babak 2 · 67&apos;
                  </span>
                  <small>Lapangan A</small>
                </div>
                <div className="mock-score">
                  <div className="mock-team">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} /> Garuda FC
                  </div>
                  <div style={{ display: "flex", alignItems: "center", gap: 4 }}>
                    <span className="mock-num">2</span>
                    <span className="mock-vs">:</span>
                    <span className="mock-num">1</span>
                  </div>
                  <div className="mock-team away">
                    Elang United <span className="crest" style={{ background: "linear-gradient(135deg,#DC2626,#991B1B)" }} />
                  </div>
                </div>
              </div>

              <table className="mock-table">
                <thead>
                  <tr>
                    <th></th>
                    <th>Klasemen Grup A</th>
                    <th className="num">M</th>
                    <th className="num">SG</th>
                    <th className="pts">Pts</th>
                  </tr>
                </thead>
                <tbody>
                  <tr className="qual">
                    <td className="pos">1</td>
                    <td>
                      <div className="tname">
                        <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} /> Garuda FC
                      </div>
                    </td>
                    <td className="num">5</td>
                    <td className="num">+9</td>
                    <td className="pts">13</td>
                  </tr>
                  <tr className="qual">
                    <td className="pos">2</td>
                    <td>
                      <div className="tname">
                        <span className="crest" style={{ background: "linear-gradient(135deg,#059669,#047857)" }} /> Rajawali
                      </div>
                    </td>
                    <td className="num">5</td>
                    <td className="num">+5</td>
                    <td className="pts">11</td>
                  </tr>
                  <tr>
                    <td className="pos">3</td>
                    <td>
                      <div className="tname">
                        <span className="crest" style={{ background: "linear-gradient(135deg,#DC2626,#991B1B)" }} /> Elang United
                      </div>
                    </td>
                    <td className="num">5</td>
                    <td className="num">+2</td>
                    <td className="pts">8</td>
                  </tr>
                  <tr>
                    <td className="pos">4</td>
                    <td>
                      <div className="tname">
                        <span className="crest" style={{ background: "linear-gradient(135deg,#D97706,#B45309)" }} /> Macan Kemayoran
                      </div>
                    </td>
                    <td className="num">5</td>
                    <td className="num">-3</td>
                    <td className="pts">4</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div className="mock-float float-cert">
            <span className="ic" style={{ background: "linear-gradient(135deg,var(--plan-professional),#B45309)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 15a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" stroke="#fff" strokeWidth="2" />
                <path d="m8.5 13-1.5 7 5-3 5 3-1.5-7" stroke="#fff" strokeWidth="2" strokeLinejoin="round" />
              </svg>
            </span>
            <div>
              <b>128 sertifikat</b>
              <small>siap dikirim</small>
            </div>
          </div>
          <div className="mock-float float-ticket">
            <span className="ic" style={{ background: "linear-gradient(135deg,var(--success),#15803D)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path d="m5 13 4 4L19 7" stroke="#fff" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </span>
            <div>
              <b>Check-in valid</b>
              <small>tiket #A-0291</small>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

export function Proof() {
  return (
    <section className="proof">
      <div className="container">
        <p className="proof-label">Dipercaya penyelenggara dari komunitas lokal hingga federasi nasional</p>
        <div className="stat-row">
          <div className="stat">
            <b>1.200+</b>
            <span>Turnamen terselenggara</span>
          </div>
          <div className="stat">
            <b>38rb</b>
            <span>Tim terdaftar</span>
          </div>
          <div className="stat">
            <b>540rb</b>
            <span>Tiket terjual</span>
          </div>
          <div className="stat">
            <b>99,9%</b>
            <span>Uptime platform</span>
          </div>
        </div>
      </div>
    </section>
  );
}
