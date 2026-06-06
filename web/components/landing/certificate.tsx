export function Certificate() {
  return (
    <section className="section" style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}>
      <div className="container">
        <div className="showcase">
          <div className="reveal">
            <span className="eyebrow">Fitur Khas</span>
            <h2 className="section-title" style={{ marginTop: 14 }}>
              Generator Sertifikat dari desainmu sendiri
            </h2>
            <p className="section-sub">
              Upload desain sertifikatmu, atur posisi setiap elemen, lalu generate ratusan sertifikat sekaligus.
              flo-event yang mencetak datanya — kamu yang punya desainnya.
            </p>
            <div className="showcase-list">
              <div className="sl-item">
                <span className="sl-ic" style={{ background: "linear-gradient(135deg,var(--brand-500),var(--brand-700))" }}>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </span>
                <div>
                  <b>Upload background sendiri</b>
                  <p>Cukup unggah file JPG/PNG resolusi tinggi sebagai dasar sertifikat.</p>
                </div>
              </div>
              <div className="sl-item">
                <span className="sl-ic" style={{ background: "linear-gradient(135deg,var(--sport-futsal),#5B21B6)" }}>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M5 5h6v6H5zM13 13h6v6h-6z" stroke="#fff" strokeWidth="2" strokeLinejoin="round" />
                    <path d="M11 8h4a2 2 0 0 1 2 2v3" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
                  </svg>
                </span>
                <div>
                  <b>Atur posisi setiap field</b>
                  <p>Geser atau input koordinat X/Y untuk nama, tim, penghargaan, logo, dan tanda tangan.</p>
                </div>
              </div>
              <div className="sl-item">
                <span className="sl-ic" style={{ background: "linear-gradient(135deg,var(--plan-professional),#B45309)" }}>
                  <svg viewBox="0 0 24 24" fill="none">
                    <path
                      d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7M3 7l9 6 9-6M3 7l2-2h14l2 2"
                      stroke="#fff"
                      strokeWidth="2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                </span>
                <div>
                  <b>Generate batch & kirim email</b>
                  <p>Ratusan sertifikat PDF sekali klik, lengkap nomor unik & QR verifikasi, langsung ke email penerima.</p>
                </div>
              </div>
            </div>
          </div>

          <div className="reveal" data-delay="120">
            <div className="cert">
              <span className="cert-hint" style={{ top: "30%", left: "50%", transform: "translateX(-50%)" }}>
                recipient_name
              </span>
              <span className="cert-seal">
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M12 15a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" stroke="#fff" strokeWidth="2" />
                  <path d="m8.5 13-1.5 7 5-3 5 3-1.5-7" stroke="#fff" strokeWidth="2" strokeLinejoin="round" />
                </svg>
              </span>
              <small>Sertifikat Penghargaan</small>
              <div className="award">Juara 1 · Top Scorer</div>
              <div className="name">Garuda FC</div>
              <div className="name-rule" />
              <div className="evt">Jakarta Cup 2026 · 14 Juni 2026</div>
              <span className="cert-no">COT-2026-00001</span>
              <span className="qr" />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
