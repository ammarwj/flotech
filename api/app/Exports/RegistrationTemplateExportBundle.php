<?php

namespace App\Exports;

use App\Models\EventCategory;
use App\Services\Catalog;
use App\Support\RegistrationTemplateColumns;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The template organizers download to bulk-import teams for one category.
 *
 * Two sheets: "Data" (what gets filled in and re-uploaded) and "Panduan"
 * (position/role keys valid for this category's sport, so an organizer isn't
 * guessing what to type in the Posisi/Peran columns). Column order on the
 * Data sheet is RegistrationTemplateColumns::forCategory() — the import reads
 * the same list by index, so the two can never disagree about what column N
 * means.
 */
class RegistrationTemplateExportBundle implements WithMultipleSheets
{
    public function __construct(protected EventCategory $category) {}

    public function sheets(): array
    {
        return [
            new RegistrationTemplateDataSheet($this->category),
            new RegistrationTemplateGuideSheet($this->category),
        ];
    }
}

class RegistrationTemplateDataSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(protected EventCategory $category) {}

    public function headings(): array
    {
        return RegistrationTemplateColumns::headings($this->category);
    }

    /** No data rows — just the header the organizer fills in. */
    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Data';
    }
}

class RegistrationTemplateGuideSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(protected EventCategory $category) {}

    public function array(): array
    {
        $sport = $this->category->sport_type;
        $rows = [
            ['Cara mengisi template ini'],
            ['1. Isi data mulai baris ke-2 di sheet "Data". Jangan ubah urutan atau nama kolom.'],
            ['2. Satu baris = satu tim' . ($this->category->rosterSize() ? ' (peserta).' : '.')],
            ['3. Kolom Posisi dan Peran memakai kode dari tabel referensi di bawah, bukan bebas ketik.'],
            ['4. Custom field dan dokumen tidak bisa lewat import — lengkapi manual lewat tombol "Ubah Tim" setelah tim masuk.'],
            [''],
        ];

        $positions = Catalog::positions($sport);
        $rows[] = ['Kode Posisi', 'Label'];
        if (empty($positions)) {
            $rows[] = ['(cabang ini tidak membatasi posisi — boleh dikosongkan)'];
        } else {
            foreach ($positions as $position) {
                $rows[] = [$position['key'], $position['label']];
            }
        }

        $rows[] = [''];

        $roles = Catalog::officialRoles($sport);
        $rows[] = ['Kode Peran Ofisial', 'Label'];
        if (empty($roles)) {
            $rows[] = ['(cabang ini tidak membatasi peran ofisial — boleh dikosongkan)'];
        } else {
            foreach ($roles as $role) {
                $rows[] = [$role['key'], $role['label']];
            }
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Panduan';
    }
}
