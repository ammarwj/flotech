<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicIdCardController extends Controller
{
    /**
     * Verify an ID card by its number — the page the printed QR points at.
     *
     * Twin of PublicCertificateController and deliberately just as narrow: it
     * confirms the card was issued and repeats what is printed on the plastic
     * the marshal is already holding.
     *
     * `photo_url` is pointedly NOT published, which is the one place this
     * differs from simply mirroring the card. The physical card carries the
     * photo; publishing it here would turn a guessable-looking card number into
     * an unauthenticated way to pull participants' headshots, including those
     * of the minors in a youth tournament. Nothing at the gate needs it — the
     * marshal is comparing the face in front of them to the card in their hand.
     */
    public function show(string $number): JsonResponse
    {
        $card = IdCard::with([
            'event:id,name,start_date,organization_id',
            'event.organization:id,name,slug,logo_url',
        ])
            ->where('card_number', $number)
            ->first();

        if (! $card) {
            return ApiResponse::error('Kartu tidak ditemukan.', null, 404);
        }

        return ApiResponse::success([
            'card_number' => $card->card_number,
            'recipient_name' => $card->recipient_name,
            'role_label' => $card->role_label,
            'team_name' => $card->team_name,
            'issued_at' => $card->issued_at,
            'event' => [
                'name' => $card->event?->name,
                'start_date' => $card->event?->start_date?->toDateString(),
            ],
            'organization' => [
                'name' => $card->event?->organization?->name,
                'slug' => $card->event?->organization?->slug,
                'logo_url' => $card->event?->organization?->logo_url,
            ],
        ]);
    }
}
