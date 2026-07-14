<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SportRequest;
use App\Http\Resources\SportResource;
use App\Models\Event;
use App\Models\Player;
use App\Models\Sport;
use App\Models\SportStat;
use App\Services\Catalog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin CRUD for sports and their stat columns. Adding a sport here is
 * all it takes for organizers to run events in it — no deploy.
 */
class SportController extends Controller
{
    public function index(): JsonResponse
    {
        $sports = Sport::with(['stats', 'positions'])->orderBy('sort_order')->get();

        return ApiResponse::success(SportResource::collection($sports));
    }

    public function store(SportRequest $request): JsonResponse
    {
        $sport = Sport::create($request->validated());
        Catalog::flush();

        return ApiResponse::success(new SportResource($sport->load(['stats', 'positions'])), 'Cabang olahraga dibuat', 201);
    }

    public function update(SportRequest $request, Sport $sport): JsonResponse
    {
        $data = $request->validated();

        // The slug is the value stored on every event — renaming it would orphan
        // them. Retire a sport with is_active instead.
        if (isset($data['slug']) && $data['slug'] !== $sport->slug && $this->inUse($sport)) {
            return ApiResponse::error(
                'Slug tidak bisa diubah karena sudah dipakai event. Nonaktifkan cabang ini jika tidak dipakai lagi.',
                ['slug' => ['Sudah dipakai event.']],
                422,
            );
        }

        $sport->update($data);
        Catalog::flush();

        return ApiResponse::success(new SportResource($sport->load(['stats', 'positions'])), 'Cabang olahraga diperbarui');
    }

    public function destroy(Sport $sport): JsonResponse
    {
        if ($this->inUse($sport)) {
            return ApiResponse::error(
                'Cabang ini sudah dipakai event — nonaktifkan saja agar data lama tetap utuh.',
                null,
                422,
            );
        }

        $sport->delete();
        Catalog::flush();

        return ApiResponse::success(null, 'Cabang olahraga dihapus');
    }

    /**
     * Replace a sport's stat columns in one go: upsert what's sent, drop what
     * isn't. Order in the payload is the display order, and the first column is
     * the stat the leaderboard ranks by.
     */
    public function syncStats(Request $request, Sport $sport): JsonResponse
    {
        $data = $request->validate([
            'stats' => ['present', 'array', 'max:12'],
            'stats.*.stat_key' => ['required', 'string', 'max:30', 'alpha_dash'],
            'stats.*.label' => ['required', 'string', 'max:60'],
            'stats.*.short' => ['required', 'string', 'max:6'],
            'stats.*.role' => ['nullable', Rule::in(SportStat::ROLES)],
            'stats.*.fair_play_weight' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $keys = [];

        foreach ($data['stats'] as $order => $stat) {
            $keys[] = $stat['stat_key'];

            $sport->stats()->updateOrCreate(
                ['stat_key' => $stat['stat_key']],
                [
                    'label' => $stat['label'],
                    'short' => $stat['short'],
                    'role' => $stat['role'] ?? null,
                    'fair_play_weight' => $stat['fair_play_weight'] ?? 0,
                    'sort_order' => $order,
                ],
            );
        }

        $sport->stats()->whereNotIn('stat_key', $keys)->delete();
        Catalog::flush();

        return ApiResponse::success(
            new SportResource($sport->load(['stats', 'positions'])),
            'Kolom statistik diperbarui',
        );
    }

    /**
     * Replace a sport's positions the same way. Renaming a label is the whole
     * point — rosters store the key, so the new name reaches every team that
     * ever entered. Dropping a key that rosters still point at is not: it would
     * leave players holding a position nobody can name.
     */
    public function syncPositions(Request $request, Sport $sport): JsonResponse
    {
        $data = $request->validate([
            'positions' => ['present', 'array', 'max:20'],
            'positions.*.position_key' => ['required', 'string', 'max:30', 'alpha_dash'],
            'positions.*.label' => ['required', 'string', 'max:60'],
        ]);

        $keys = array_column($data['positions'], 'position_key');

        $dropped = $sport->positions()->whereNotIn('position_key', $keys)->pluck('position_key');
        $used = $this->positionsInUse($sport, $dropped->all());

        if ($used !== []) {
            return ApiResponse::error(
                'Posisi '.implode(', ', $used).' masih dipakai pemain — ganti namanya saja, jangan dihapus.',
                ['positions' => ['Masih dipakai pemain.']],
                422,
            );
        }

        foreach ($data['positions'] as $order => $position) {
            $sport->positions()->updateOrCreate(
                ['position_key' => $position['position_key']],
                ['label' => $position['label'], 'sort_order' => $order],
            );
        }

        $sport->positions()->whereNotIn('position_key', $keys)->delete();
        Catalog::flush();

        return ApiResponse::success(
            new SportResource($sport->load(['stats', 'positions'])),
            'Posisi diperbarui',
        );
    }

    protected function inUse(Sport $sport): bool
    {
        return Event::where('sport_type', $sport->slug)->exists();
    }

    /**
     * Which of these position keys any player of this sport still holds.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    protected function positionsInUse(Sport $sport, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return Player::whereIn('position', $keys)
            ->whereHas('team.event', fn ($q) => $q->where('sport_type', $sport->slug))
            ->distinct()
            ->pluck('position')
            ->all();
    }
}
