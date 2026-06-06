const STEPS = [
  {
    n: 1,
    title: "Buat Event",
    desc: "Pilih cabang & format, atur detail turnamen, konfigurasi form registrasi. Publish dan landing page langsung aktif.",
    delay: undefined,
  },
  {
    n: 2,
    title: "Terima Pendaftaran",
    desc: "Tim mendaftar lewat landing page, upload dokumen, bayar online. Kamu tinggal approve atau reject.",
    delay: "80",
  },
  {
    n: 3,
    title: "Jalankan Turnamen",
    desc: "Generate jadwal otomatis, input hasil per pertandingan, klasemen & bracket update real-time.",
    delay: "160",
  },
  {
    n: 4,
    title: "Tutup & Apresiasi",
    desc: "Generate sertifikat juara secara batch, kirim via email, lalu ekspor laporan turnamen lengkap.",
    delay: "240",
  },
];

export function HowItWorks() {
  return (
    <section className="section" id="cara-kerja">
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Cara Kerja</span>
          <h2 className="section-title">Dari nol ke turnamen tayang dalam 4 langkah</h2>
        </div>
        <div className="steps">
          {STEPS.map((s) => (
            <div key={s.n} className="step reveal" data-delay={s.delay}>
              <div className="step-n">{s.n}</div>
              <h3>{s.title}</h3>
              <p>{s.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
