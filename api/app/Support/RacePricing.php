<?php

namespace App\Support;

use App\Exceptions\PaymentException;
use App\Models\Event;
use App\Models\RaceCategory;
use App\Models\RacePeriod;
use Illuminate\Support\Carbon;

/**
 * The single answer to "what does this entry cost right now?".
 *
 * Two halves that have to be read together and are therefore read in one place:
 * which sale window the clock is in (the event's `race_periods`), and what this
 * category charges during it (`race_category_prices`). Shaped like PaymentRails
 * and HybridConfig — a support class every caller goes through — because a
 * second reader of either half drifts, and the drift reads as a runner charged
 * a price the page was not showing them.
 *
 * > **Invarian: jam SERVER yang memilih periode, bukan parameter klien.** Tidak
 * > ada method di sini yang menerima `race_period_id` dari luar, dan itu yang
 * > membuat "diskon early bird" tidak bisa diminta lewat payload. Ujilah dengan
 * > **membandingkan** dua POST identik yang dipisah `Carbon::setTestNow()`
 * > melintasi batas periode: harga tersimpannya wajib berbeda. Assert satu
 * > harga saja akan lolos walau periodenya diambil dari payload, sampai hari
 * > penjualan sungguhan.
 *
 * > **Invarian: celah antar periode = penjualan TUTUP, bukan jatuh ke periode
 * > terdekat.** Organizer yang mengetik Early Bird 1–10 lalu Pre Sale 12–20
 * > telah meninggalkan tanggal 11 tanpa harga, dan satu-satunya jawaban yang
 * > tidak mengarang angka adalah menolak. Memilih periode terdekat berarti
 * > menagih harga dari jendela yang tertulis sudah berakhir — dan tidak ada
 * > pihak yang bisa menunjukkan di mana angka itu disetujui. Ujilah dengan
 * > **membandingkan** satu instan di dalam jendela dengan satu instan di
 * > celahnya; assert "early bird terbaca" saja akan lolos walau celahnya
 * > diam-diam ikut membacanya.
 *
 * Tumpang-tindih jendela tidak ditangani di sini karena ia tidak bisa terjadi:
 * `RaceCategoryController` menolaknya di sisi tulis. Kalau toh ada (baris lama,
 * tulisan manual ke DB), `periodAt()` memilih yang paling awal mulai — jawaban
 * yang menguntungkan pendaftar, bukan platform.
 */
final class RacePricing
{
    /**
     * The window the given instant falls in, or null when none does.
     *
     * Null is three different situations the caller must keep apart: the event
     * has no periods at all (organizer has not set it up), the clock is before
     * the first one, or it is in a gap. All three mean "cannot be entered now",
     * which is why they share a return value — but the *message* differs, so
     * the branching lives in quote() where there is something to say.
     *
     * Reads the loaded relation when it is there. The public registration page
     * loads periods and categories together, and a lazy query per category
     * would be an N+1 across a page whose whole job is to list prices.
     */
    public function periodAt(Event $event, ?Carbon $at = null): ?RacePeriod
    {
        $at ??= Carbon::now();

        return $event->racePeriods
            ->filter(fn (RacePeriod $period) => $period->contains($at))
            // The relation is already ordered by `starts_at`, so first() is the
            // earliest-starting match. See the class docblock on overlaps.
            ->first();
    }

    /**
     * What this category charges during that window, or null when it does not
     * sell then.
     *
     * **Null is not zero.** A category with no price row for a period is not on
     * offer during it — see the migration. Returning 0.0 here would sign people
     * up for free the moment an organizer adds a period and has not finished
     * filling in the matrix.
     */
    public function priceFor(RaceCategory $category, RacePeriod $period): ?float
    {
        $row = $category->prices
            ->firstWhere('race_period_id', $period->id);

        return $row === null ? null : (float) $row->price;
    }

    /**
     * The quote a new entry is created from: which window, and how much.
     *
     * Throws rather than returning null, because every caller would otherwise
     * have to turn the same three nulls into the same three messages. 422 with
     * no `feature` key: this is not a plan entitlement, it is a sale that is
     * not open, and carrying `errors.feature` would make isPlanLimitError()
     * swallow it into a toast instead of showing it on the form.
     *
     * @return array{period: RacePeriod, price: float}
     *
     * @throws PaymentException
     */
    public function quote(RaceCategory $category, ?Carbon $at = null): array
    {
        $event = $category->event;

        $period = $this->periodAt($event, $at);

        if (! $period) {
            // One message for all three nulls above. A runner can act on none of
            // the differences — "the organizer has not set up periods yet" and
            // "you are between two of them" are the same closed door to them —
            // and naming which would leak the organizer's setup state onto a
            // public page.
            throw new PaymentException('Pendaftaran untuk lomba ini sedang tidak dibuka.');
        }

        $price = $this->priceFor($category, $period);

        if ($price === null) {
            // Deliberately a different message: this door is category-specific
            // and the runner *can* act on it by picking another category, which
            // is exactly what the public form offers them.
            throw new PaymentException(
                "Kategori ini tidak dijual pada periode {$period->name}.",
                ['race_category_id' => ['Kategori ini tidak tersedia pada periode pendaftaran saat ini.']],
            );
        }

        return ['period' => $period, 'price' => $price];
    }

    /**
     * Every price this category has, period by period, for the public page and
     * the organizer's matrix.
     *
     * Carries `is_current` so the page can mark the row that is in force rather
     * than recomputing which one it is — the same reasoning as publishing
     * `effective_payment_method` instead of letting the client recombine the
     * rule. A period the category does not sell in is included with a null
     * price: "not sold then" is information a runner deciding when to register
     * wants, and dropping the row would read as a period that does not exist.
     *
     * @return list<array{period_id: string, name: string, starts_at: string, ends_at: string, price: float|null, is_current: bool}>
     */
    public function schedule(RaceCategory $category, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();
        $current = $this->periodAt($category->event, $at);

        return $category->event->racePeriods
            ->map(fn (RacePeriod $period) => [
                'period_id' => $period->id,
                'name' => $period->name,
                'starts_at' => $period->starts_at->toIso8601String(),
                'ends_at' => $period->ends_at->toIso8601String(),
                'price' => $this->priceFor($category, $period),
                'is_current' => $current !== null && $current->id === $period->id,
            ])
            ->values()
            ->all();
    }
}
