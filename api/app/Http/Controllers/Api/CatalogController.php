<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The admin-managed vocabulary of the platform, read by the web app on boot:
 * which sports exist (and their stat columns), which tournament formats,
 * tiebreakers, draw methods, knockout rounds and sponsor tiers are on offer.
 *
 * Public — the marketing site and every public event page needs it.
 */
class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'sports' => Catalog::sports()->all(),
            'tournament_formats' => Catalog::options('tournament_format'),
            'tiebreakers' => Catalog::options('tiebreaker'),
            'draw_methods' => Catalog::options('draw_method'),
            'knockout_rounds' => Catalog::options('knockout_round'),
            'sponsor_tiers' => Catalog::options('sponsor_tier'),
        ]);
    }
}
