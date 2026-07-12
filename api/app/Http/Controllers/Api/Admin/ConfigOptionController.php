<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigOptionRequest;
use App\Models\ConfigOption;
use App\Services\Catalog;
use App\Support\ApiResponse;
use App\Support\Engines;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin CRUD for the reference options: tournament formats, tiebreakers,
 * draw methods, knockout rounds, sponsor tiers.
 *
 * A format is a *preset* over an engine that exists in code, so an admin can
 * ship "Liga 2 Putaran" (engine `league`, defaults `legs: 2`) without a deploy —
 * but cannot invent an engine that nothing can run.
 */
class ConfigOptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $options = ConfigOption::when(
            $request->query('group'),
            fn ($q, $group) => $q->where('group', $group),
        )
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($options);
    }

    public function store(ConfigOptionRequest $request): JsonResponse
    {
        $option = ConfigOption::create($request->validated());
        Catalog::flush();

        return ApiResponse::success($option, 'Opsi konfigurasi dibuat', 201);
    }

    public function update(ConfigOptionRequest $request, ConfigOption $configOption): JsonResponse
    {
        $configOption->update($request->validated());
        Catalog::flush();

        return ApiResponse::success($configOption, 'Opsi konfigurasi diperbarui');
    }

    public function destroy(ConfigOption $configOption): JsonResponse
    {
        $configOption->delete();
        Catalog::flush();

        return ApiResponse::success(null, 'Opsi konfigurasi dihapus');
    }

    /** What the code can run — fills the "engine" dropdowns in the admin UI. */
    public function engines(): JsonResponse
    {
        return ApiResponse::success(Engines::all());
    }
}
