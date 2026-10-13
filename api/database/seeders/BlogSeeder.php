<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Seeder;

/**
 * Starter content so the Blog link in the footer never lands on an empty page.
 *
 * Idempotent, keyed on `slug` — and `firstOrCreate` rather than
 * `updateOrCreate`, for the same reason SiteSettingSeeder gives: this runs on
 * production via `migrate --seed`, and an article super_admin has since
 * rewritten must not be reverted to this file's wording on the next deploy.
 */
class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'panduan', 'name' => 'Panduan', 'description' => 'Langkah demi langkah memakai flo-event.', 'sort_order' => 10],
            ['slug' => 'tips-turnamen', 'name' => 'Tips Turnamen', 'description' => 'Praktik baik menyelenggarakan kompetisi.', 'sort_order' => 20],
            ['slug' => 'produk', 'name' => 'Produk', 'description' => 'Fitur baru dan perubahan di flo-event.', 'sort_order' => 30],
        ];

        foreach ($categories as $category) {
            BlogCategory::firstOrCreate(['slug' => $category['slug']], $category);
        }

        $panduan = BlogCategory::where('slug', 'panduan')->first();
        $tips = BlogCategory::where('slug', 'tips-turnamen')->first();
        $produk = BlogCategory::where('slug', 'produk')->first();

        $posts = [
            [
                'slug' => 'cara-membuat-turnamen-pertama-di-flo-event',
                'blog_category_id' => $panduan?->id,
                'title' => 'Cara Membuat Turnamen Pertama di flo-event',
                'excerpt' => 'Dari membeli paket sampai bracket tercetak: panduan lengkap menyiapkan turnamen pertamamu dalam satu sore.',
                'is_featured' => true,
                'published_at' => now()->subDays(21),
                'body' => <<<'HTML'
<p>Menyiapkan turnamen pertama selalu terasa paling berat, karena semuanya baru sekaligus: peserta, jadwal, klasemen, sampai tiket penonton. Panduan ini membawamu dari akun kosong sampai jadwal babak pertama terbit, dan bisa diselesaikan dalam satu sore.</p>
<h2>1. Buat organisasi</h2>
<p>Setelah mendaftar, hal pertama yang diminta adalah nama organisasi penyelenggara. Nama ini yang muncul di halaman publik eventmu dan di setiap dokumen yang terbit — invoice, sertifikat, dan ID card peserta. Kamu bisa mengubahnya nanti, tapi alamat profil publikmu mengikuti nama pertama, jadi pilih yang memang kamu pakai sehari-hari.</p>
<h2>2. Pilih paket untuk event ini</h2>
<p>Di flo-event, paket dibeli <strong>sekali untuk satu event</strong>, bukan berlangganan bulanan. Tidak ada tanggal kedaluwarsa: event yang berjalan lintas bulan tidak dikenai biaya tambahan. Dua event milik organisasi yang sama boleh memakai paket berbeda — liga internal yang kecil tidak perlu membayar seperti turnamen terbuka.</p>
<p>Kredit yang sudah dibayar tapi belum dipakai akan menunggu di halaman Tagihan sampai kamu benar-benar membuat eventnya. Tidak ada yang hangus.</p>
<h2>3. Isi detail event</h2>
<p>Nama, cabang olahraga, tanggal, lokasi, dan jendela pendaftaran. Jendela pendaftaran ini yang nanti membuka dan menutup form publik secara otomatis — kamu tidak perlu mengingat untuk menutupnya manual di hari H.</p>
<h2>4. Susun kategori</h2>
<p>Satu event bisa punya beberapa kategori: U-15, U-17, Putra, Putri, dan seterusnya. Tiap kategori punya format sendiri — liga, gugur, atau grup lalu gugur — jadi kategori usia muda yang hanya berisi enam tim boleh memakai liga penuh sementara kategori utama memakai fase grup.</p>
<h2>5. Buka pendaftaran</h2>
<p>Bagikan tautan halaman event. Tim mendaftar sendiri, mengunggah berkas, dan mengisi daftar pemain lengkap dengan nomor punggung. Kamu tinggal menyetujui atau menolak dari dashboard. Kalau ada tim yang mendaftar di luar jalur — lewat WhatsApp, misalnya — kamu tetap bisa memasukkannya secara manual.</p>
<h2>6. Buat jadwal</h2>
<p>Setelah peserta disetujui, tekan Buat Jadwal. Sistem menyusun pertandingan sesuai format kategori, menyebarnya di rentang tanggal event, dan mengatur lapangan. Hasilnya bisa kamu geser satu per satu kalau ada kendala venue.</p>
<p>Sejak titik ini, klasemen, bracket, dan statistik pemain berjalan sendiri setiap kali hasil pertandingan dikonfirmasi.</p>
HTML,
            ],
            [
                'slug' => 'memilih-format-kompetisi-liga-gugur-atau-grup',
                'blog_category_id' => $tips?->id,
                'title' => 'Memilih Format Kompetisi: Liga, Gugur, atau Grup?',
                'excerpt' => 'Jumlah peserta, jumlah hari, dan jumlah lapangan menentukan format yang masuk akal. Ini cara menghitungnya sebelum undangan disebar.',
                'published_at' => now()->subDays(14),
                'body' => <<<'HTML'
<p>Format kompetisi bukan soal selera. Ia ditentukan tiga angka yang sudah kamu tahu sebelum undangan disebar: berapa peserta, berapa hari, dan berapa lapangan. Salah memilih berarti salah satu dari dua hal — turnamen tidak selesai tepat waktu, atau separuh peserta pulang setelah satu pertandingan.</p>
<h2>Liga penuh</h2>
<p>Semua melawan semua. Paling adil, dan paling mahal: delapan tim berarti 28 pertandingan. Cocok untuk kompetisi berjalan yang digelar mingguan, atau turnamen kecil dengan enam tim ke bawah.</p>
<p>Kalau pesertamu di atas delapan dan waktumu satu akhir pekan, liga penuh hampir pasti tidak muat.</p>
<h2>Sistem gugur</h2>
<p>Paling hemat waktu: 16 tim selesai dalam 15 pertandingan. Harganya adalah separuh peserta hanya bermain sekali. Untuk turnamen berbayar, itu keluhan yang akan kamu dengar langsung di lapangan.</p>
<p>Obatnya gugur ganda — peserta baru tersingkir setelah dua kali kalah — dengan biaya sekitar dua kali lipat jumlah pertandingan.</p>
<h2>Grup lalu gugur</h2>
<p>Format yang dipakai hampir semua turnamen besar, dan biasanya jawaban yang benar. Setiap tim dijamin bermain tiga kali di fase grup, lalu yang lolos masuk bracket. Jumlah pertandingannya bisa diatur lewat ukuran grup: grup berisi empat tim lebih hemat daripada grup berisi lima.</p>
<h2>Swiss</h2>
<p>Untuk peserta banyak dan waktu terbatas, tanpa ada yang tersingkir. Setiap tim bermain jumlah ronde yang sama, dan lawan ronde berikutnya ditentukan klasemen saat itu — yang menang bertemu yang menang. Tiga puluh dua peserta selesai dalam lima ronde, dan semua orang bermain lima kali.</p>
<h2>Cara cepat memutuskan</h2>
<p>Hitung kapasitasmu lebih dulu: jumlah lapangan dikali jumlah jam main per hari, dibagi durasi satu pertandingan termasuk jeda. Itu batas atas pertandinganmu. Baru setelah itu pilih format yang muat di dalamnya.</p>
HTML,
            ],
            [
                'slug' => 'menyiapkan-penjualan-tiket-online',
                'blog_category_id' => $panduan?->id,
                'title' => 'Menyiapkan Penjualan Tiket Online untuk Eventmu',
                'excerpt' => 'Kategori tiket, tiket harian, QR di pintu masuk, dan kapan uangnya cair ke rekeningmu.',
                'published_at' => now()->subDays(9),
                'body' => <<<'HTML'
<p>Tiket penonton adalah sumber pemasukan yang paling sering terlewat oleh penyelenggara turnamen amatir, biasanya karena menjualnya di pintu masuk terasa lebih sederhana daripada menyiapkan sistem. Padahal selisihnya besar: penjualan daring berjalan sejak jauh hari, dan kamu tahu berapa orang yang akan datang sebelum hari H.</p>
<h2>Kategori tiket</h2>
<p>Satu event boleh punya beberapa kategori tiket — Reguler, VIP, Tribun Timur — masing-masing dengan harga dan kuota sendiri. Kuota ditegakkan sistem, jadi tidak ada risiko terjual melebihi kapasitas.</p>
<h2>Tiket harian</h2>
<p>Untuk event beberapa hari, satu kategori bisa dijual per hari. Penonton memilih tanggal saat membeli, dan QR-nya hanya berlaku di tanggal itu. Berguna untuk turnamen akhir pekan yang final-nya jauh lebih ramai daripada babak penyisihan.</p>
<h2>QR dan pemindaian di pintu</h2>
<p>Setiap tiket terbit dengan QR unik. Di pintu masuk, petugasmu memindainya lewat ponsel biasa — tidak perlu alat khusus. Tiket yang sudah dipakai langsung ditolak pada pemindaian kedua, jadi satu tiket tidak bisa dipakai dua orang.</p>
<p>Beri akun petugas peran <strong>operator</strong>. Peran itu bisa memindai tiket tapi tidak bisa menyentuh uang, mengubah rekening tujuan, atau membeli paket.</p>
<h2>Kapan uangnya cair</h2>
<p>Pembayaran masuk ke akun platform lebih dulu, lalu bagianmu ditampung di dompet organizer. Saldo menjadi tersedia setelah melewati pukul 01.00 WIB berikutnya, dan bisa kamu tarik ke rekening bank atau e-wallet kapan saja setelah itu. Untuk event yang sudah selesai, batas minimum penarikan dibebaskan — sisa saldo berapa pun bisa dibawa pulang.</p>
HTML,
            ],
            [
                'slug' => 'enam-kesalahan-umum-penyelenggara-turnamen',
                'blog_category_id' => $tips?->id,
                'title' => 'Enam Kesalahan yang Paling Sering Terjadi di Turnamen Amatir',
                'excerpt' => 'Dikumpulkan dari penyelenggara yang sudah mengalaminya. Semuanya bisa dicegah sebelum pertandingan pertama ditiup.',
                'published_at' => now()->subDays(5),
                'body' => <<<'HTML'
<p>Daftar ini dikumpulkan dari penyelenggara yang sudah mengalaminya sendiri. Semuanya bisa dicegah, dan semuanya lebih murah dicegah sebelum pertandingan pertama ditiup.</p>
<h2>1. Regulasi tiebreaker baru ditulis saat dibutuhkan</h2>
<p>Dua tim berakhir dengan poin sama di hari terakhir, dan baru saat itu panitia berunding soal selisih gol atau head-to-head. Apa pun keputusannya akan terlihat memihak. Tulis urutan tiebreaker di regulasi sejak awal, dan sebarkan bersama undangan.</p>
<h2>2. Jadwal tidak menyisakan waktu pemulihan</h2>
<p>Tim yang bermain dua kali berturut-turut dengan jeda dua puluh menit akan bermain buruk, dan risikonya cedera. Beri jarak minimal satu pertandingan di antara dua laga tim yang sama.</p>
<h2>3. Berkas peserta diverifikasi di hari pertandingan</h2>
<p>Akta kelahiran untuk kategori usia, kartu pelajar, surat keterangan sehat — semuanya menumpuk di meja panitia pada pagi hari pertama, dan antrean itu memundurkan kick-off. Minta semuanya diunggah saat pendaftaran dan verifikasi jauh hari.</p>
<h2>4. Tidak ada rencana untuk hujan</h2>
<p>Untuk cabang luar ruang, tentukan sejak awal apa yang terjadi kalau satu hari hilang: pertandingan dipadatkan, durasi babak dipotong, atau format kategori diubah. Keputusan yang dibuat dalam keadaan basah dan terburu-buru selalu lebih buruk.</p>
<h2>5. Satu orang memegang semua catatan</h2>
<p>Skor di buku tulis satu orang, pembayaran di catatan ponsel orang lain, dan daftar pemain di grup WhatsApp. Saat ada yang disengketakan, tidak ada satu pun sumber yang bisa dirujuk bersama. Pakai satu sistem yang bisa dibuka semua panitia.</p>
<h2>6. Sanksi kartu tidak dicatat</h2>
<p>Pemain yang seharusnya absen karena akumulasi kartu tetap turun, dan protes datang setelah pertandingan selesai — saat hasilnya sudah tidak bisa diperbaiki tanpa merusak klasemen. Catat kartu segera setelah pertandingan, dan pastikan panitia lapangan bisa melihat daftar larangan sebelum kick-off.</p>
HTML,
            ],
            [
                'slug' => 'format-swiss-untuk-peserta-banyak',
                'blog_category_id' => $produk?->id,
                'title' => 'Format Swiss: Peserta Banyak, Waktu Terbatas, Tidak Ada yang Pulang Cepat',
                'excerpt' => 'Setiap peserta bermain jumlah ronde yang sama, lawan ditentukan klasemen berjalan, dan tidak ada yang tersingkir.',
                'published_at' => now()->subDays(2),
                'body' => <<<'HTML'
<p>Swiss adalah jawaban untuk masalah yang sering dihadapi turnamen terbuka: peserta terlalu banyak untuk liga penuh, tapi sistem gugur membuat separuh orang pulang setelah satu pertandingan — padahal mereka membayar biaya pendaftaran yang sama.</p>
<h2>Cara kerjanya</h2>
<p>Setiap peserta bermain jumlah ronde yang sama, jauh lebih sedikit daripada jumlah peserta. Ronde pertama diundi acak. Mulai ronde kedua, lawan ditentukan klasemen saat itu: yang menang bertemu yang menang, yang kalah bertemu yang kalah. Tidak ada yang tersingkir, dan dua peserta tidak akan pernah dipasangkan dua kali.</p>
<p>Hasilnya, setelah beberapa ronde, klasemen atas berisi peserta yang memang sudah saling mengalahkan — bukan yang kebetulan mendapat undian ringan.</p>
<h2>Berapa ronde yang dibutuhkan</h2>
<p>Patokannya logaritma basis dua dari jumlah peserta: 16 peserta cukup empat ronde, 32 peserta lima ronde, 64 peserta enam ronde. Sistem menghitungnya otomatis, dan kamu tetap bisa menimpanya kalau jadwalmu hanya muat untuk lebih sedikit.</p>
<h2>Bye</h2>
<p>Kalau jumlah peserta ganjil, satu peserta mendapat bye tiap ronde: dihitung menang, tanpa bermain. Yang paling jarang kebagian bye didahulukan, sehingga selisihnya tidak pernah lebih dari satu untuk siapa pun.</p>
<h2>Buchholz</h2>
<p>Di Swiss, dua peserta dengan jumlah kemenangan sama belum tentu sekuat. Buchholz menjumlahkan poin seluruh lawan yang pernah dihadapi: peserta yang melewati jalan lebih berat berada di atas. Ini tiebreaker default untuk kategori Swiss, dan tidak mengubah apa pun di format lain.</p>
<h2>Ronde demi ronde</h2>
<p>Ronde berikutnya baru bisa dibuat setelah seluruh hasil ronde sebelumnya dikonfirmasi — karena lawannya memang diturunkan dari hasil itu. Tombolnya mati sampai syarat itu terpenuhi, lengkap dengan keterangan berapa pertandingan yang masih ditunggu.</p>
HTML,
            ],
        ];

        foreach ($posts as $post) {
            $body = HtmlSanitizer::clean($post['body']);

            BlogPost::firstOrCreate(['slug' => $post['slug']], [
                'blog_category_id' => $post['blog_category_id'],
                // No author_id: this content predates any account, and the
                // byline is a snapshot column precisely so it does not need one.
                'author_id' => null,
                'author_name' => 'Tim flo-event',
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                'body' => $body,
                'published_at' => $post['published_at'],
                'is_featured' => $post['is_featured'] ?? false,
                'read_minutes' => HtmlSanitizer::readMinutes($body),
            ]);
        }
    }
}
