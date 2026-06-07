import Link from "next/link";

export function Cta() {
  return (
    <section className="section cta-band">
      <div className="container">
        <div className="cta-card reveal">
          <h2>Siap menggelar turnamenmu?</h2>
          <p>
            Bergabung dengan 1.200+ penyelenggara yang sudah meninggalkan spreadsheet. Mulai gratis hari ini — setup
            turnamen pertamamu dalam 10 menit.
          </p>
          <div className="hero-cta">
            <Link href="/register" className="btn btn-primary btn-lg">
              Mulai Gratis Sekarang
            </Link>
            <Link href="/event" className="btn btn-secondary btn-lg">
              Lihat Contoh Event
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
}
