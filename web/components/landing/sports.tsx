const SPORTS = [
  { color: "var(--sport-football)", icon: "⚽", name: "Sepak Bola", formats: "Liga · Knockout · Grup + Knockout", delay: undefined },
  { color: "var(--sport-mini-soccer)", icon: "🥅", name: "Mini Soccer", formats: "Liga · Knockout · Grup + Knockout", delay: "60" },
  { color: "var(--sport-futsal)", icon: "🏟️", name: "Futsal", formats: "Liga · Knockout · Grup + Knockout", delay: "120" },
  { color: "var(--sport-badminton)", icon: "🏸", name: "Badminton", formats: "Liga · Knockout · Round Robin", delay: "180" },
  { color: "var(--sport-padel)", icon: "🎾", name: "Padel", formats: "Liga · Knockout · Round Robin", delay: "240" },
  { color: "var(--sport-volleyball)", icon: "🏐", name: "Voli", formats: "Liga · Knockout · Pool Play", delay: "300" },
];

export function Sports() {
  return (
    <section
      className="section section-sm"
      id="cabang"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Cabang Olahraga</span>
          <h2 className="section-title">Lima cabang, banyak format turnamen</h2>
          <p className="section-sub">
            Setiap cabang punya aturan skor, statistik, dan klasemen yang tepat sesuai karakter olahraganya.
          </p>
        </div>
        <div className="sports-grid">
          {SPORTS.map((s) => (
            <article
              key={s.name}
              className="sport reveal"
              data-delay={s.delay}
              style={{ ["--sc" as string]: s.color }}
            >
              <div className="sport-ic">{s.icon}</div>
              <h4>{s.name}</h4>
              <p>{s.formats}</p>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
