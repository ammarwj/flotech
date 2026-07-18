/**
 * Nama tim dan nama pemain selalu disimpan kapital, supaya roster, kartu
 * peserta, bagan pertandingan, dan sertifikat tampil seragam apa pun cara user
 * mengetiknya.
 *
 * Jalankan di setiap keystroke (pola sama seperti `phoneInput`) agar teks yang
 * di-paste ikut terkapitalkan. Panjang string tidak berubah, jadi posisi kursor
 * tetap saat user mengedit di tengah teks.
 */
export function nameInput(v: string): string {
  return v.toUpperCase();
}
