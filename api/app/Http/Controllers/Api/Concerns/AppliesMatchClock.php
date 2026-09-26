<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Resources\MatchResource;
use App\Models\GameMatch;
use App\Services\MatchClockService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Satu aksi jam, dua pintu.
 *
 * Organizer (`MatchController`) dan petugas pertandingan
 * (`OfficiatingMatchController`) berbeda **hanya** pada cara mereka menemukan
 * laganya — yang satu lewat tenant, yang satu lewat event yang dipasang
 * middleware. Sisanya identik, dan trait ini yang menjaganya tetap identik:
 * daftar aksi yang sah, kalimat penolakannya, dan pesan suksesnya.
 *
 * Kalau masing-masing menyimpan salinannya sendiri, satu papan skor akan
 * menampilkan "Babak 2" sementara papan di sebelahnya masih "Babak 1" untuk
 * laga yang sama — pola yang sama yang sudah ditulis panjang di
 * `MatchResultService`.
 */
trait AppliesMatchClock
{
    protected function applyClock(Request $request, GameMatch $match): JsonResponse
    {
        $validated = $request->validate(
            ['action' => ['required', Rule::in(['start', 'pause', 'resume', 'advance', 'reset'])]],
            ['action.in' => 'Aksi jam pertandingan tidak dikenali.'],
        );

        $clock = app(MatchClockService::class);

        $match = match ($validated['action']) {
            'start' => $clock->start($match),
            'pause' => $clock->pause($match),
            'resume' => $clock->resume($match),
            'advance' => $clock->advance($match),
            'reset' => $clock->reset($match),
        };

        $rules = $clock->snapshot($match);
        $label = $rules['label'] ?? 'Babak';

        $message = match ($validated['action']) {
            'start', 'resume' => 'Jam pertandingan berjalan',
            'pause' => 'Jam pertandingan dijeda',
            'advance' => "{$label} {$match->period} dimulai",
            'reset' => 'Jam pertandingan direset',
        };

        return ApiResponse::success(
            new MatchResource($match->load(['homeTeam', 'awayTeam', 'category.event'])),
            $message,
        );
    }
}
