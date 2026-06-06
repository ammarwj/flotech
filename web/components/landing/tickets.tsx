export function Tickets() {
  return (
    <section className="section">
      <div className="container">
        <div className="showcase flip">
          <div className="reveal" data-delay="120">
            <div className="ticket">
              <div className="ticket-top">
                <small>E-Ticket · Tribun Utara</small>
                <b>Jakarta Cup 2026 — Final</b>
              </div>
              <div className="ticket-perf" />
              <div className="ticket-body">
                <div className="ticket-info">
                  <div className="ti-row">
                    <small>Nama</small>
                    <b>Andi Saputra</b>
                  </div>
                  <div className="ti-row">
                    <small>Tanggal</small>
                    <b>14 Jun 2026 · 19:00</b>
                  </div>
                  <div className="ti-row">
                    <small>Kursi</small>
                    <b className="mono">UTR-A-291</b>
                  </div>
                </div>
                <div className="ticket-qr" />
              </div>
              <div className="ticket-status">
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="m5 13 4 4L19 7" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                Tiket valid · belum digunakan
              </div>
            </div>
          </div>

          <div className="reveal">
            <span className="eyebrow">Tiket & Check-in</span>
            <h2 className="section-title" style={{ marginTop: 14 }}>
              Tiket QR Code, scan langsung dari kamera
            </h2>
            <p className="section-sub">
              Penonton beli tiket online dan terima e-tiket dengan QR Code unik. Operator scan lewat kamera HP — tanpa
              install aplikasi — dengan validasi server-side sekali pakai.
            </p>
            <div className="showcase-list">
              <div className="sl-item">
                <span className="sl-ic" style={{ background: "linear-gradient(135deg,var(--brand-500),var(--brand-700))" }}>
                  <svg viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="3" width="7" height="7" rx="1" stroke="#fff" strokeWidth="2" />
                    <rect x="14" y="3" width="7" height="7" rx="1" stroke="#fff" strokeWidth="2" />
                    <rect x="3" y="14" width="7" height="7" rx="1" stroke="#fff" strokeWidth="2" />
                    <path d="M14 14h3v3m4 0v4m-7 0h3" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
                  </svg>
                </span>
                <div>
                  <b>QR unik per tiket</b>
                  <p>Setiap tiket punya kode unik terenkripsi, tidak bisa dipalsukan atau dipakai ulang.</p>
                </div>
              </div>
              <div className="sl-item">
                <span className="sl-ic" style={{ background: "linear-gradient(135deg,var(--success),#047857)" }}>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path
                      d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M3 12h18"
                      stroke="#fff"
                      strokeWidth="2"
                      strokeLinecap="round"
                    />
                  </svg>
                </span>
                <div>
                  <b>Scan berbasis kamera</b>
                  <p>Halaman scanner langsung di browser, validasi instan ✅ Valid / ❌ Sudah digunakan.</p>
                </div>
              </div>
              <div className="sl-item">
                <span className="sl-ic" style={{ background: "linear-gradient(135deg,var(--sport-football),#1558CC)" }}>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M3 3v18h18" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
                    <path d="m7 14 3-3 3 3 5-6" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </span>
                <div>
                  <b>Laporan check-in real-time</b>
                  <p>Pantau jumlah penonton yang sudah masuk dan rekap keuangan secara langsung.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
