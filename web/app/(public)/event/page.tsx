import type { Metadata } from "next";
import Link from "next/link";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { Countdown } from "@/components/event/countdown";
import { RevealInit } from "@/components/landing/reveal-init";
import { LogoMark } from "@/components/landing/icons";
import "../event-shell.css";

export const metadata: Metadata = {
  title: "Jakarta Cup 2026 — flo-event",
  description:
    "Jakarta Cup 2026 — Turnamen Sepak Bola. Daftarkan timmu atau beli tiket. Jadwal, klasemen, dan bracket live.",
};

export default function EventPage() {
  return (
    <>
      {/* ===================== EVENT HERO ===================== */}
      <header className="ehero">
        <div className="container ehero-inner">
          <div className="ehero-top">
            <Link href="/" className="ehero-back">
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M19 12H5m6-6-6 6 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
              Didukung flo-event
            </Link>
            <ThemeToggleButton />
          </div>

          <div className="ehero-grid">
            <div>
              <div className="ehero-badges">
                <span className="ehero-badge sport">⚽ Sepak Bola</span>
                <span className="ehero-badge">Format Liga + Playoff</span>
                <span className="ehero-badge">
                  <span style={{ width: 7, height: 7, borderRadius: "50%", background: "#22D3A7", display: "inline-block" }} /> Pendaftaran Dibuka
                </span>
              </div>
              <h1>Jakarta Cup 2026</h1>
              <div className="ehero-info">
                <span>
                  <svg viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="4" width="18" height="17" rx="2" stroke="currentColor" strokeWidth="2" />
                    <path d="M3 9h18M8 2v4M16 2v4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                  </svg>
                  14–22 Juni 2026
                </span>
                <span>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
                    <circle cx="12" cy="10" r="2.5" stroke="currentColor" strokeWidth="2" />
                  </svg>
                  GBK Soccer Field, Jakarta
                </span>
                <span>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                    <circle cx="9" cy="7" r="4" stroke="currentColor" strokeWidth="2" />
                  </svg>
                  16 Tim
                </span>
              </div>
              <div className="ehero-cta">
                <a href="#daftar" className="btn btn-primary btn-lg">
                  Daftar Tim Sekarang
                </a>
                <a href="#tiket" className="btn btn-secondary btn-lg">
                  <svg viewBox="0 0 24 24" fill="none">
                    <path
                      d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6Z"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinejoin="round"
                    />
                  </svg>
                  Beli Tiket
                </a>
              </div>
            </div>

            <Countdown />
          </div>
        </div>
      </header>

      {/* ===================== STAT BAR ===================== */}
      <section className="ebar">
        <div className="container ebar-grid">
          <div className="ebar-cell">
            <b>16</b>
            <span>Tim Peserta</span>
          </div>
          <div className="ebar-cell">
            <b>32</b>
            <span>Pertandingan</span>
          </div>
          <div className="ebar-cell">
            <b>4</b>
            <span>Grup</span>
          </div>
          <div className="ebar-cell">
            <b>Rp 50jt</b>
            <span>Total Hadiah</span>
          </div>
        </div>
      </section>

      {/* ===================== MAIN ===================== */}
      <main className="section">
        <div className="container elayout">
          {/* LEFT COLUMN */}
          <div>
            {/* SCHEDULE */}
            <div className="esection-title">
              <h2>Jadwal Pertandingan</h2>
              <span className="pill">
                <span style={{ width: 7, height: 7, borderRadius: "50%", background: "currentColor", display: "inline-block" }} /> Hari ini
              </span>
            </div>

            <div className="match-day">Sabtu, 14 Juni 2026</div>

            <div className="match-card reveal">
              <div className="match-time">
                <span className="badge badge-live">
                  <span className="dot" /> LIVE
                </span>
                <small style={{ display: "block", marginTop: 4 }}>67&apos;</small>
              </div>
              <div className="match-teams">
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} /> Garuda FC <span className="sc">2</span>
                </div>
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#DC2626,#991B1B)" }} /> Elang United <span className="sc">1</span>
                </div>
              </div>
              <div className="match-meta">
                <small>Grup A</small>
                <small>Lapangan A</small>
              </div>
            </div>

            <div className="match-card reveal" data-delay="60">
              <div className="match-time">
                <b>17:30</b>
                <small>WIB</small>
              </div>
              <div className="match-teams">
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#059669,#047857)" }} /> Rajawali
                </div>
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#D97706,#B45309)" }} /> Macan Kemayoran
                </div>
              </div>
              <div className="match-meta">
                <span className="badge badge-warning">Berikutnya</span>
                <small>Grup A · Lap. A</small>
              </div>
            </div>

            <div className="match-card reveal" data-delay="120">
              <div className="match-time">
                <b>19:00</b>
                <small>WIB</small>
              </div>
              <div className="match-teams">
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#7C3AED,#5B21B6)" }} /> Naga Biru
                </div>
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#DB2777,#9D174D)" }} /> Banteng FC
                </div>
              </div>
              <div className="match-meta">
                <span className="badge" style={{ background: "var(--bg-soft)", color: "var(--text-muted)" }}>
                  Terjadwal
                </span>
                <small>Grup B · Lap. B</small>
              </div>
            </div>

            <div className="match-day">Minggu, 15 Juni 2026</div>

            <div className="match-card reveal">
              <div className="match-time">
                <b>15:00</b>
                <small>WIB</small>
              </div>
              <div className="match-teams">
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#0EA5E9,#0369A1)" }} /> Hiu Laut
                </div>
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#65A30D,#3F6212)" }} /> Komodo FC
                </div>
              </div>
              <div className="match-meta">
                <span className="badge" style={{ background: "var(--bg-soft)", color: "var(--text-muted)" }}>
                  Terjadwal
                </span>
                <small>Grup C · Lap. A</small>
              </div>
            </div>

            <div className="match-card reveal" data-delay="60">
              <div className="match-time">
                <b>17:30</b>
                <small>WIB</small>
              </div>
              <div className="match-teams">
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#E11D48,#9F1239)" }} /> Singa Api
                </div>
                <div className="match-team">
                  <span className="crest" style={{ background: "linear-gradient(135deg,#475569,#1E293B)" }} /> Badak Hitam
                </div>
              </div>
              <div className="match-meta">
                <span className="badge" style={{ background: "var(--bg-soft)", color: "var(--text-muted)" }}>
                  Terjadwal
                </span>
                <small>Grup C · Lap. B</small>
              </div>
            </div>

            {/* STANDINGS */}
            <div className="esection-title" style={{ marginTop: 56 }}>
              <h2>Klasemen Grup A</h2>
            </div>
            <table className="stable">
              <thead>
                <tr>
                  <th className="pos">#</th>
                  <th className="l">Tim</th>
                  <th>M</th>
                  <th>M</th>
                  <th>S</th>
                  <th>K</th>
                  <th>SG</th>
                  <th>Poin</th>
                </tr>
              </thead>
              <tbody>
                <tr className="qual">
                  <td className="pos">1</td>
                  <td className="l">
                    <div className="tname">
                      <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} /> Garuda FC
                    </div>
                  </td>
                  <td>5</td>
                  <td>4</td>
                  <td>1</td>
                  <td>0</td>
                  <td>+9</td>
                  <td className="pts">13</td>
                </tr>
                <tr className="qual">
                  <td className="pos">2</td>
                  <td className="l">
                    <div className="tname">
                      <span className="crest" style={{ background: "linear-gradient(135deg,#059669,#047857)" }} /> Rajawali
                    </div>
                  </td>
                  <td>5</td>
                  <td>3</td>
                  <td>2</td>
                  <td>0</td>
                  <td>+5</td>
                  <td className="pts">11</td>
                </tr>
                <tr className="playoff">
                  <td className="pos">3</td>
                  <td className="l">
                    <div className="tname">
                      <span className="crest" style={{ background: "linear-gradient(135deg,#DC2626,#991B1B)" }} /> Elang United
                    </div>
                  </td>
                  <td>5</td>
                  <td>2</td>
                  <td>2</td>
                  <td>1</td>
                  <td>+2</td>
                  <td className="pts">8</td>
                </tr>
                <tr>
                  <td className="pos">4</td>
                  <td className="l">
                    <div className="tname">
                      <span className="crest" style={{ background: "linear-gradient(135deg,#D97706,#B45309)" }} /> Macan Kemayoran
                    </div>
                  </td>
                  <td>5</td>
                  <td>1</td>
                  <td>1</td>
                  <td>3</td>
                  <td>-3</td>
                  <td className="pts">4</td>
                </tr>
                <tr>
                  <td className="pos">5</td>
                  <td className="l">
                    <div className="tname">
                      <span className="crest" style={{ background: "linear-gradient(135deg,#475569,#1E293B)" }} /> Badak Hitam
                    </div>
                  </td>
                  <td>5</td>
                  <td>0</td>
                  <td>0</td>
                  <td>5</td>
                  <td>-13</td>
                  <td className="pts">0</td>
                </tr>
              </tbody>
            </table>
            <div className="stable-legend">
              <span>
                <i style={{ background: "var(--success)" }} /> Lolos perempat final
              </span>
              <span>
                <i style={{ background: "var(--warning)" }} /> Babak playoff
              </span>
              <span>
                <i style={{ background: "var(--border-strong)" }} /> Tersingkir
              </span>
            </div>

            {/* BRACKET */}
            <div className="esection-title" style={{ marginTop: 56 }}>
              <h2>Bracket Babak Gugur</h2>
            </div>
            <div className="ebracket">
              <div className="ebracket-col">
                <div className="rnd">Perempat Final</div>
                <div className="ematch">
                  <div className="row win">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} />
                    <span className="nm">Garuda FC</span>
                    <span className="sc">3</span>
                  </div>
                  <div className="row out">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#DB2777,#9D174D)" }} />
                    <span className="nm">Banteng FC</span>
                    <span className="sc">1</span>
                  </div>
                </div>
                <div className="ematch">
                  <div className="row win">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#059669,#047857)" }} />
                    <span className="nm">Rajawali</span>
                    <span className="sc">2</span>
                  </div>
                  <div className="row out">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#0EA5E9,#0369A1)" }} />
                    <span className="nm">Hiu Laut</span>
                    <span className="sc">0</span>
                  </div>
                </div>
                <div className="ematch">
                  <div className="row win">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#7C3AED,#5B21B6)" }} />
                    <span className="nm">Naga Biru</span>
                    <span className="sc">2</span>
                  </div>
                  <div className="row out">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#65A30D,#3F6212)" }} />
                    <span className="nm">Komodo FC</span>
                    <span className="sc">1</span>
                  </div>
                </div>
                <div className="ematch">
                  <div className="row win">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#E11D48,#9F1239)" }} />
                    <span className="nm">Singa Api</span>
                    <span className="sc">4</span>
                  </div>
                  <div className="row out">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#D97706,#B45309)" }} />
                    <span className="nm">Macan</span>
                    <span className="sc">2</span>
                  </div>
                </div>
              </div>
              <div className="ebracket-col">
                <div className="rnd">Semifinal</div>
                <div className="ematch">
                  <div className="row win">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} />
                    <span className="nm">Garuda FC</span>
                    <span className="sc">2</span>
                  </div>
                  <div className="row out">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#059669,#047857)" }} />
                    <span className="nm">Rajawali</span>
                    <span className="sc">1</span>
                  </div>
                </div>
                <div className="ematch">
                  <div className="row win">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#7C3AED,#5B21B6)" }} />
                    <span className="nm">Naga Biru</span>
                    <span className="sc">3</span>
                  </div>
                  <div className="row out">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#E11D48,#9F1239)" }} />
                    <span className="nm">Singa Api</span>
                    <span className="sc">2</span>
                  </div>
                </div>
              </div>
              <div className="ebracket-col">
                <div className="rnd">Final</div>
                <div className="ematch">
                  <div className="row">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }} />
                    <span className="nm">Garuda FC</span>
                    <span className="sc">—</span>
                  </div>
                  <div className="row">
                    <span className="crest" style={{ background: "linear-gradient(135deg,#7C3AED,#5B21B6)" }} />
                    <span className="nm">Naga Biru</span>
                    <span className="sc">—</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* RIGHT SIDEBAR */}
          <aside>
            {/* top scorer */}
            <div className="card scard reveal">
              <h3>
                <svg viewBox="0 0 24 24" fill="none">
                  <path
                    d="m12 2 2.4 7.4H22l-6 4.5 2.3 7.1L12 16.6 5.7 21l2.3-7.1-6-4.5h7.6z"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinejoin="round"
                  />
                </svg>
                Top Scorer
              </h3>
              <div className="scorer">
                <span className="rk">1</span>
                <span className="av" style={{ background: "linear-gradient(135deg,#1E6FFF,#1558CC)" }}>
                  BS
                </span>
                <span className="nm">
                  Bagus Saputra<small>Garuda FC</small>
                </span>
                <span className="goals">
                  8<small> gol</small>
                </span>
              </div>
              <div className="scorer">
                <span className="rk">2</span>
                <span className="av" style={{ background: "linear-gradient(135deg,#E11D48,#9F1239)" }}>
                  RF
                </span>
                <span className="nm">
                  Rian Firmansyah<small>Singa Api</small>
                </span>
                <span className="goals">
                  6<small> gol</small>
                </span>
              </div>
              <div className="scorer">
                <span className="rk">3</span>
                <span className="av" style={{ background: "linear-gradient(135deg,#7C3AED,#5B21B6)" }}>
                  AP
                </span>
                <span className="nm">
                  Andi Pratama<small>Naga Biru</small>
                </span>
                <span className="goals">
                  5<small> gol</small>
                </span>
              </div>
            </div>

            {/* tickets */}
            <div className="card scard reveal" id="tiket" data-delay="60">
              <h3>
                <svg viewBox="0 0 24 24" fill="none">
                  <path
                    d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6Z"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinejoin="round"
                  />
                </svg>
                Tiket Penonton
              </h3>
              <div className="price-tier">
                <div>
                  <b>Tribun Umum</b>
                  <small>Akses tribun samping</small>
                </div>
                <span className="amt">Rp 25rb</span>
              </div>
              <div className="price-tier">
                <div>
                  <b>Tribun Utama</b>
                  <small>Tribun tengah + merchandise</small>
                </div>
                <span className="amt">Rp 75rb</span>
              </div>
              <div className="price-tier">
                <div>
                  <b>VIP Final</b>
                  <small>Kursi VIP semua laga</small>
                </div>
                <span className="amt">Rp 250rb</span>
              </div>
              <a href="#" className="btn btn-primary btn-block" style={{ marginTop: 18 }}>
                Beli Tiket Sekarang
              </a>
            </div>

            {/* organizer */}
            <div className="card scard reveal" data-delay="120">
              <h3>
                <svg viewBox="0 0 24 24" fill="none">
                  <rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" strokeWidth="2" />
                  <path d="M8 12h8M8 8h8M8 16h5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                </svg>
                Penyelenggara
              </h3>
              <div style={{ display: "flex", alignItems: "center", gap: 13 }}>
                <span
                  style={{
                    width: 46,
                    height: 46,
                    borderRadius: 12,
                    background: "linear-gradient(135deg,#1E6FFF,#1558CC)",
                    display: "grid",
                    placeItems: "center",
                    color: "#fff",
                    flexShrink: 0,
                  }}
                >
                  <LogoMark width={22} height={22} />
                </span>
                <div>
                  <b style={{ fontFamily: "var(--font-display)", fontSize: 15 }}>Jakarta Sports EO</b>
                  <small style={{ display: "block", color: "var(--text-muted)", fontSize: 13 }}>12 turnamen terselenggara</small>
                </div>
              </div>
              <a href="#" className="btn btn-secondary btn-block btn-sm" style={{ marginTop: 16 }}>
                Hubungi Penyelenggara
              </a>
            </div>
          </aside>
        </div>

        {/* SPONSORS */}
        <div className="container" style={{ marginTop: 24 }}>
          <div className="esection-title">
            <h2>Sponsor &amp; Partner</h2>
          </div>
          <div className="sponsors">
            <div className="sponsor reveal">NusantaraBank</div>
            <div className="sponsor reveal" data-delay="40">
              SportaWear
            </div>
            <div className="sponsor reveal" data-delay="80">
              EnerGo
            </div>
            <div className="sponsor reveal" data-delay="120">
              PrimaTel
            </div>
            <div className="sponsor reveal" data-delay="160">
              Juara<span style={{ color: "var(--brand-600)" }}>Air</span>
            </div>
          </div>
        </div>

        {/* REGISTER CTA */}
        <div className="container" style={{ marginTop: 56 }} id="daftar">
          <div className="ereg reveal">
            <h2>Daftarkan timmu di Jakarta Cup 2026</h2>
            <p>Kuota 16 tim — tersisa 3 slot. Lengkapi data tim, roster pemain, dan bayar biaya registrasi langsung online.</p>
            <div className="ehero-cta">
              <a href="#" className="btn btn-primary btn-lg">
                Daftar Tim — Rp 1,5jt
              </a>
              <a href="#" className="btn btn-secondary btn-lg">
                Unduh Peraturan
              </a>
            </div>
            <div className="deadline">
              <svg viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="2" />
                <path d="M12 7v5l3 2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
              Pendaftaran ditutup 8 Juni 2026, 23:59 WIB
            </div>
          </div>
        </div>
      </main>

      {/* ===================== FOOTER ===================== */}
      <footer className="footer">
        <div className="container">
          <div className="footer-bottom" style={{ marginTop: 0, paddingTop: 0, borderTop: "none" }}>
            <Link href="/" className="logo">
              <span className="logo-mark">
                <LogoMark />
              </span>
              flo<span>-event</span>
            </Link>
            <span>Halaman event ini dibuat dengan flo-event · © 2026</span>
          </div>
        </div>
      </footer>

      <RevealInit />
    </>
  );
}
