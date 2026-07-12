export function Features() {
  return (
    <section className="section" id="fitur">
      <div className="container">
        <div className="section-head reveal">
          <span className="eyebrow">Fitur Unggulan</span>
          <h2 className="section-title">Satu platform untuk seluruh siklus turnamen</h2>
          <p className="section-sub">
            Tak perlu lagi spreadsheet, grup WhatsApp, dan Google Form yang berserakan. Semua workflow turnamen
            terhubung dan otomatis.
          </p>
        </div>

        <div className="feat-grid">
          {/* registrasi */}
          <article className="feat span6 reveal">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--brand-500),var(--brand-700))" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
                <circle cx="9" cy="7" r="4" stroke="#fff" strokeWidth="2" />
                <path d="M19 8v6M22 11h-6" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
              </svg>
            </div>
            <h3>Registrasi Tim &amp; Peserta</h3>
            <p>
              Form registrasi yang bisa dikonfigurasi sendiri, upload dokumen langsung ke cloud, approval manual atau
              otomatis, lengkap dengan pembayaran terintegrasi.
            </p>
          </article>

          {/* klasemen */}
          <article className="feat span6 reveal" data-delay="80">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--success),#047857)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M3 3v18h18" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
                <rect x="7" y="11" width="3" height="6" rx="1" fill="#fff" />
                <rect x="12" y="7" width="3" height="10" rx="1" fill="#fff" />
                <rect x="17" y="13" width="3" height="4" rx="1" fill="#fff" />
              </svg>
            </div>
            <h3>Klasemen Real-time</h3>
            <p>
              Aturan klasemen yang dapat dikonfigurasi per cabang dan per event. Begitu hasil dikonfirmasi, klasemen dan
              statistik otomatis ter-update.
            </p>
          </article>

          {/* jadwal (wide w/ visual) */}
          <article className="feat span8 reveal">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--accent-blue),#1558CC)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <rect x="3" y="4" width="18" height="17" rx="2" stroke="#fff" strokeWidth="2" />
                <path d="M3 9h18M8 2v4M16 2v4" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
              </svg>
            </div>
            <h3>Jadwal Pertandingan Otomatis</h3>
            <p>
              Generate jadwal otomatis untuk format Liga, Knockout, atau Hybrid. Edit manual kapan saja dengan
              notifikasi otomatis ke seluruh peserta.
            </p>
            <div className="feat-visual">
              <div className="sched">
                <div className="sched-row">
                  <span className="sched-time">15:00</span>
                  <span className="sched-match">
                    Garuda FC vs Rajawali<small>Penyisihan Grup A · Lapangan A</small>
                  </span>
                  <span className="badge badge-live">
                    <span className="dot" /> Live
                  </span>
                </div>
                <div className="sched-row">
                  <span className="sched-time">17:30</span>
                  <span className="sched-match">
                    Elang United vs Macan Kemayoran<small>Penyisihan Grup A · Lapangan A</small>
                  </span>
                  <span className="badge badge-warning">Berikutnya</span>
                </div>
                <div className="sched-row">
                  <span className="sched-time">19:00</span>
                  <span className="sched-match">
                    Naga Biru vs Banteng FC<small>Penyisihan Grup B · Lapangan B</small>
                  </span>
                  <span className="badge" style={{ background: "var(--bg-soft)", color: "var(--text-muted)" }}>
                    Terjadwal
                  </span>
                </div>
              </div>
            </div>
          </article>

          {/* bracket (visual) */}
          <article className="feat span4 reveal" data-delay="80">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--accent-purple),#5B21B6)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path
                  d="M3 5h6v6H3M3 13h6v6H3M9 8h4v8h4M17 10V6h4M17 18v-4h4"
                  stroke="#fff"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </div>
            <h3>Bracket Turnamen</h3>
            <p>Visualisasi knockout interaktif yang auto-update &amp; bisa di-embed.</p>
            <div className="feat-visual">
              <div className="bracket">
                <div className="bracket-col">
                  <div className="bk">
                    <div className="r win">
                      <span>Garuda</span>
                      <span className="s">3</span>
                    </div>
                    <div className="r">
                      <span>Naga</span>
                      <span className="s">1</span>
                    </div>
                  </div>
                  <div className="bk">
                    <div className="r">
                      <span>Elang</span>
                      <span className="s">0</span>
                    </div>
                    <div className="r win">
                      <span>Rajawali</span>
                      <span className="s">2</span>
                    </div>
                  </div>
                </div>
                <div className="bracket-col">
                  <div className="bk">
                    <div className="r win">
                      <span>Garuda</span>
                      <span className="s">2</span>
                    </div>
                    <div className="r">
                      <span>Rajawali</span>
                      <span className="s">1</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </article>

          {/* input hasil */}
          <article className="feat span4 reveal">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--warning),#B45309)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <rect x="4" y="3" width="16" height="18" rx="2" stroke="#fff" strokeWidth="2" />
                <path d="M8 8h8M8 12h8M8 16h5" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
              </svg>
            </div>
            <h3>Input Hasil Terverifikasi</h3>
            <p>
              Operator input skor &amp; statistik → admin verifikasi → terkonfirmasi. Workflow berlapis dengan audit
              trail di setiap langkah.
            </p>
          </article>

          {/* statistik */}
          <article className="feat span4 reveal" data-delay="80">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--accent-pink),#9D174D)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path
                  d="m12 2 2.4 7.4H22l-6 4.5 2.3 7.1L12 16.6 5.7 21l2.3-7.1-6-4.5h7.6z"
                  stroke="#fff"
                  strokeWidth="2"
                  strokeLinejoin="round"
                />
              </svg>
            </div>
            <h3>Statistik &amp; Leaderboard</h3>
            <p>
              Akumulasi statistik individual otomatis. Top scorer, top assist, MVP, hingga Fair Play Award — semua
              terhitung sendiri.
            </p>
          </article>

          {/* landing page per event */}
          <article className="feat span4 reveal" data-delay="160">
            <div className="feat-ic" style={{ background: "linear-gradient(135deg,var(--accent-green),#065F46)" }}>
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M3 21V8l9-5 9 5v13" stroke="#fff" strokeWidth="2" strokeLinejoin="round" />
                <path d="M9 21v-6h6v6M3 12h18" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
              </svg>
            </div>
            <h3>Landing Page per Event</h3>
            <p>
              Setiap event punya halaman publik sendiri dengan URL unik — countdown, jadwal, klasemen, dan tombol
              daftar/beli tiket.
            </p>
          </article>
        </div>
      </div>
    </section>
  );
}
