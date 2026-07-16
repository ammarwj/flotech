import type { Metadata } from "next";

import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";

export const metadata: Metadata = {
  title: "Ketentuan Layanan — flo-event",
  description:
    "Ketentuan Layanan flo-event, termasuk larangan tegas atas segala bentuk perjudian — termasuk hadiah yang bersumber dari akumulasi biaya pendaftaran peserta.",
};

const UPDATED = "16 Juli 2026";

export default function KetentuanPage() {
  return (
    <>
      <Nav />
      <main className="container" style={{ paddingBlock: 56, maxWidth: 820 }}>
        <p className="text-sm font-semibold uppercase tracking-wide text-[var(--brand-600)]">Legal</p>
        <h1 className="mt-2 text-3xl font-bold sm:text-4xl" style={{ fontFamily: "var(--font-display)" }}>
          Ketentuan Layanan
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">Terakhir diperbarui: {UPDATED}</p>

        <p className="mt-6 leading-relaxed text-muted-foreground">
          Dengan membuat akun, menyelenggarakan event, mendaftarkan tim, atau membeli tiket di flo-event,
          kamu menyetujui ketentuan di bawah ini. Ketentuan ini berlaku untuk seluruh penyelenggara
          (organizer), peserta, dan penonton.
        </p>

        {/* The core clause the platform is built around. */}
        <section
          className="mt-8 rounded-xl border p-5"
          style={{
            borderColor: "color-mix(in srgb, var(--danger) 40%, transparent)",
            background: "color-mix(in srgb, var(--danger) 8%, transparent)",
          }}
        >
          <h2 className="text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            1. Larangan Perjudian
          </h2>
          <p className="mt-3 leading-relaxed">
            <strong>
              flo-event melarang keras segala bentuk perjudian melalui platform, dalam bentuk apa pun.
            </strong>{" "}
            Penyelenggara dan pengguna dilarang menyelenggarakan, memfasilitasi, atau mempromosikan
            perjudian menggunakan layanan ini. Yang termasuk perjudian mencakup, tetapi tidak terbatas pada:
          </p>
          <ul className="mt-3 grid gap-2 leading-relaxed">
            <li>
              • <strong>Hadiah yang bersumber dari akumulasi biaya pendaftaran peserta</strong> —
              mengumpulkan uang pendaftaran lalu membagikannya kembali sebagai hadiah/prize pool
              (pot bersama) kepada pemenang.
            </li>
            <li>• Taruhan dalam bentuk apa pun antar peserta, penonton, atau pihak lain atas hasil pertandingan.</li>
            <li>• Undian berbayar, lotre, arisan berhadiah, atau skema sejenis yang bergantung pada keberuntungan.</li>
            <li>• Skema lain yang secara materiil berfungsi sebagai perjudian meskipun dengan nama berbeda.</li>
          </ul>
          <p className="mt-3 leading-relaxed">
            Biaya pendaftaran hanya boleh digunakan untuk biaya operasional penyelenggaraan turnamen.
            Hadiah harus bersumber dari sponsor atau dana penyelenggara sendiri —{" "}
            <strong>bukan dari pengumpulan (pooling) uang pendaftaran peserta.</strong>
          </p>
        </section>

        <Section title="2. Tanggung Jawab Penyelenggara">
          Penyelenggara bertanggung jawab penuh atas legalitas, isi, dan pelaksanaan event yang dibuatnya,
          termasuk memastikan event bebas dari unsur perjudian sebagaimana diatur pada bagian 1.
          Penyelenggara menjamin memiliki hak dan izin yang diperlukan untuk menyelenggarakan event.
        </Section>

        <Section title="3. Penegakan">
          Jika flo-event menemukan indikasi pelanggaran atas ketentuan ini, kami berhak — tanpa pemberitahuan
          terlebih dahulu — menangguhkan atau menghapus event terkait, menahan pencairan dana, serta
          menangguhkan atau menutup akun yang bersangkutan. Penyelenggara tetap bertanggung jawab atas
          kewajiban dan konsekuensi hukum yang timbul dari pelanggaran tersebut.
        </Section>

        <Section title="4. Pembayaran & Pengembalian Dana">
          Pembayaran biaya pendaftaran dan tiket diproses melalui penyedia pembayaran pihak ketiga. Bagian
          hak penyelenggara ditampung di dompet platform dan dicairkan sesuai aturan pencairan yang berlaku.
          Pengembalian dana (refund) mengikuti kebijakan yang berlaku dan tidak berlaku untuk event yang
          dihapus akibat pelanggaran.
        </Section>

        <Section title="5. Penggunaan yang Dilarang">
          Selain perjudian, dilarang menggunakan layanan untuk aktivitas ilegal, penipuan, pencucian uang,
          pelanggaran hak pihak lain, atau tindakan apa pun yang melanggar hukum yang berlaku di Indonesia.
        </Section>

        <Section title="6. Perubahan Ketentuan">
          Ketentuan ini dapat diperbarui sewaktu-waktu. Versi terbaru yang tayang di halaman ini adalah versi
          yang berlaku. Dengan terus menggunakan layanan, kamu dianggap menyetujui perubahan tersebut.
        </Section>

        <p className="mt-8 text-sm text-muted-foreground">
          Ada pertanyaan seputar ketentuan ini? Hubungi tim flo-event melalui kanal dukungan resmi.
        </p>
      </main>
      <Footer />
    </>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mt-8">
      <h2 className="text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        {title}
      </h2>
      <p className="mt-3 leading-relaxed text-muted-foreground">{children}</p>
    </section>
  );
}
