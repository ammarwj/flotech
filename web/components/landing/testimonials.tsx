import { StarIcon } from "./icons";

type Testimonial = {
  quote: string;
  initials: string;
  avatar: string;
  name: string;
  role: string;
  delay?: string;
};

const TESTIMONIALS: Testimonial[] = [
  {
    quote:
      "Dulu rekap klasemen liga futsal kami makan waktu berjam-jam tiap pekan. Sekarang otomatis begitu skor dikonfirmasi. Game changer buat EO kecil.",
    initials: "RP",
    avatar: "linear-gradient(135deg,var(--brand-500),var(--brand-700))",
    name: "Rizky Pratama",
    role: "Ketua Liga Futsal Bandung",
  },
  {
    quote:
      "Fitur sertifikatnya juara. Kami upload desain sendiri, atur posisi sekali, generate 200 sertifikat pemain dalam hitungan menit.",
    initials: "SW",
    avatar: "linear-gradient(135deg,var(--accent-purple),#5B21B6)",
    name: "Sari Wulandari",
    role: "Event Organizer, Surabaya",
    delay: "80",
  },
  {
    quote:
      "Tiket QR + scan check-in bikin pintu masuk turnamen badminton kami nggak antre lagi. Validasi instan, anti tiket palsu.",
    initials: "DA",
    avatar: "linear-gradient(135deg,var(--accent-pink),#9D174D)",
    name: "Dimas Aryo",
    role: "PB Garuda Mas, Yogyakarta",
    delay: "160",
  },
  {
    quote:
      "Landing page per event-nya rapi banget. Peserta tinggal scan, lihat jadwal & klasemen tanpa harus tanya-tanya admin lagi.",
    initials: "NF",
    avatar: "linear-gradient(135deg,var(--success),#047857)",
    name: "Nadia Fitri",
    role: "Panitia Voli Antar-Kampus",
  },
  {
    quote:
      "Naik dari Basic ke Pro pas turnamen tahunan kami membesar. Upgrade-nya mulus, datanya aman semua. Worth it.",
    initials: "HW",
    avatar: "linear-gradient(135deg,var(--plan-professional),#B45309)",
    name: "Hendra Wijaya",
    role: "Padel Community Jakarta",
    delay: "80",
  },
];

export function Testimonials() {
  return (
    <section className="section">
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Kata Mereka</span>
          <h2 className="section-title">Penyelenggara di seluruh Indonesia</h2>
        </div>
        <div className="tcol">
          {TESTIMONIALS.map((t) => (
            <div key={t.name} className="tcard reveal" data-delay={t.delay}>
              <div className="tstars">
                {Array.from({ length: 5 }).map((_, i) => (
                  <StarIcon key={i} />
                ))}
              </div>
              <p>&ldquo;{t.quote}&rdquo;</p>
              <div className="who">
                <span className="av" style={{ background: t.avatar }}>
                  {t.initials}
                </span>
                <div>
                  <b>{t.name}</b>
                  <small>{t.role}</small>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
