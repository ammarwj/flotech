<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicCertificateController extends Controller
{
    /**
     * Verify a certificate by its number — the page the printed QR points at.
     *
     * Deliberately narrow: it confirms the document exists and repeats what it
     * says. No emails, no ids, nothing that isn't already printed on the paper
     * the visitor is holding.
     */
    public function show(string $number): JsonResponse
    {
        $certificate = Certificate::with(['event:id,name,start_date,organization_id', 'event.organization:id,name,slug,logo_url'])
            ->where('certificate_number', $number)
            ->first();

        if (! $certificate) {
            return ApiResponse::error('Sertifikat tidak ditemukan.', null, 404);
        }

        return ApiResponse::success([
            'certificate_number' => $certificate->certificate_number,
            'recipient_name' => $certificate->recipient_name,
            'team_name' => $certificate->team_name,
            'award_title' => $certificate->award_title,
            'issued_at' => $certificate->issued_at,
            'event' => [
                'name' => $certificate->event?->name,
                'start_date' => $certificate->event?->start_date?->toDateString(),
            ],
            'organization' => [
                'name' => $certificate->event?->organization?->name,
                'slug' => $certificate->event?->organization?->slug,
                'logo_url' => $certificate->event?->organization?->logo_url,
            ],
        ]);
    }
}
