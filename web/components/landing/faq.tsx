"use client";

import { useState } from "react";
import { ChevronDown } from "./icons";

const FAQS = [
  {
    q: "Paket paling murah mulai dari berapa?",
    a: "Paket Basic Rp 49.000/bulan — kamu bisa menjalankan 1 event aktif dengan maksimal 8 tim, lengkap dengan jadwal, klasemen, dan bracket. Upgrade kapan saja saat butuh fitur tiket atau sertifikat. Bayar tahunan untuk hemat 20%.",
  },
  {
    q: "Cabang olahraga apa saja yang didukung?",
    a: "Saat ini sepak bola, futsal, badminton, padel, dan voli — masing-masing dengan aturan skor, statistik, dan klasemen yang sesuai. Basket dan tenis menyusul di roadmap berikutnya.",
  },
  {
    q: "Bagaimana cara kerja generator sertifikat?",
    a: "Kamu upload desain sertifikatmu sendiri (JPG/PNG), atur posisi tiap elemen — nama, tim, penghargaan, logo, tanda tangan — lalu generate batch. Setiap sertifikat dapat nomor unik dan QR verifikasi, bisa di-download ZIP atau dikirim via email (paket Pro ke atas).",
  },
  {
    q: "Apakah saya bisa upgrade atau downgrade paket?",
    a: "Bisa, langsung dari dashboard kapan saja. Saat downgrade, fitur premium terkunci tapi seluruh data turnamenmu tetap aman dan tersimpan.",
  },
  {
    q: "Metode pembayaran apa yang tersedia?",
    a: "Lewat Midtrans: Virtual Account semua bank besar, QRIS, e-wallet (GoPay/OVO/DANA/ShopeePay), serta kartu kredit/debit. Berlaku untuk langganan, biaya registrasi, dan pembelian tiket.",
  },
  {
    q: "Apakah boleh mengadakan event perjudian atau hadiah dari uang pendaftaran?",
    a: "Tidak. flo-event melarang segala bentuk perjudian, termasuk hadiah yang dikumpulkan (pooling) dari biaya pendaftaran peserta. Biaya pendaftaran hanya untuk operasional penyelenggaraan; hadiah harus bersumber dari sponsor atau dana penyelenggara. Pelanggaran dapat berujung penghapusan event dan penangguhan akun. Selengkapnya di halaman Ketentuan Layanan.",
  },
  {
    q: "Apakah data turnamen saya aman?",
    a: "Setiap organizer terisolasi sebagai tenant terpisah. Kami pakai HTTPS, enkripsi data, audit trail di setiap aksi penting, serta patuh UU PDP Indonesia. Uptime platform dijaga di 99,9%.",
  },
];

export function Faq() {
  const [open, setOpen] = useState(0);

  return (
    <section
      className="section section-sm"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">FAQ</span>
          <h2 className="section-title">Pertanyaan yang sering ditanyakan</h2>
        </div>
        <div className="faq-list">
          {FAQS.map((item, i) => (
            <div key={item.q} className={`faq-item${open === i ? " open" : ""}`}>
              <button className="faq-q" onClick={() => setOpen(open === i ? -1 : i)}>
                {item.q}
                <span className="chev">
                  <ChevronDown />
                </span>
              </button>
              <div className="faq-a">
                <p>{item.a}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
