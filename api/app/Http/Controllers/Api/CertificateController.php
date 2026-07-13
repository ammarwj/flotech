<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Certificate\GenerateCertificatesRequest;
use App\Http\Resources\CertificateResource;
use App\Jobs\SendCertificateJob;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Services\CertificateService;
use App\Services\PlanGate;
use App\Services\R2StorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected CertificateService $certificates,
        protected R2StorageService $r2,
    ) {}

    /** Issued certificates, newest first; optionally narrowed to one event. */
    public function index(Request $request, string $organization): JsonResponse
    {
        $certificates = $this->org($request)
            ->certificates()
            ->with('event:id,name')
            ->when($request->query('event_id'), fn ($q, $id) => $q->where('event_id', $id))
            ->latest('issued_at')
            ->get();

        return ApiResponse::success(CertificateResource::collection($certificates));
    }

    /** Who can still be handed a certificate for this event. */
    public function recipients(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->findEvent($request, $event);
        $pool = $this->certificates->recipients($model);

        return ApiResponse::success([
            'teams' => $pool['teams']->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'city' => $team->city,
                'email' => $team->manager?->email,
                'players_count' => $team->players->count(),
            ])->values(),
            'players' => $pool['players']->map(fn ($player) => [
                'id' => $player->id,
                'name' => $player->full_name,
                'team_id' => $player->team_id,
                'jersey_number' => $player->jersey_number,
            ])->values(),
        ]);
    }

    /** Issue + render a batch of certificates, optionally emailing them. */
    public function generate(GenerateCertificatesRequest $request, string $organization, string $event): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $model = $this->findEvent($request, $event);
        $data = $request->validated();

        $sendEmail = (bool) ($data['send_email'] ?? false);

        if ($sendEmail && ! $this->gate->allows($org, 'certificate_email')) {
            return ApiResponse::error(
                'Pengiriman sertifikat via email tidak tersedia di paketmu.',
                ['feature' => 'certificate_email'],
                403,
            );
        }

        $template = $org->certificateTemplates()
            ->where('id', $data['certificate_template_id'])
            ->firstOrFail();

        $issued = $this->certificates->issueBatch(
            $model,
            $template,
            $data['recipients'],
            $data['award_title'],
        );

        if ($sendEmail) {
            $issued->each(fn (Certificate $certificate) => SendCertificateJob::dispatch($certificate));
        }

        $skipped = count($data['recipients']) - $issued->count();

        $message = $issued->count().' sertifikat diterbitkan.'
            .($skipped > 0 ? " {$skipped} dilewati (sudah punya penghargaan ini)." : '');

        return ApiResponse::success(
            CertificateResource::collection($issued),
            $message,
            201,
        );
    }

    /** Queue the email for one already-issued certificate. */
    public function send(Request $request, string $organization, string $certificate): JsonResponse
    {
        $org = $this->org($request);

        if (! $this->gate->allows($org, 'certificate_email')) {
            return ApiResponse::error(
                'Pengiriman sertifikat via email tidak tersedia di paketmu.',
                ['feature' => 'certificate_email'],
                403,
            );
        }

        $model = $this->find($org, $certificate);

        if (! $model->recipient_email) {
            return ApiResponse::error('Penerima ini tidak punya alamat email.', null, 422);
        }

        SendCertificateJob::dispatch($model);

        return ApiResponse::success(null, 'Sertifikat sedang dikirim ke '.$model->recipient_email);
    }

    /** Stream the rendered PDF. The bucket key never leaves the server. */
    public function download(Request $request, string $organization, string $certificate): StreamedResponse|JsonResponse
    {
        $model = $this->find($this->org($request), $certificate);

        if (! $model->pdf_key) {
            return ApiResponse::error('PDF sertifikat belum tersedia.', null, 404);
        }

        $filename = str_replace('/', '-', $model->certificate_number).'.pdf';

        return $this->r2->disk()->download($model->pdf_key, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function destroy(Request $request, string $organization, string $certificate): JsonResponse
    {
        $model = $this->find($this->org($request), $certificate);

        if ($model->pdf_key) {
            $this->r2->delete($model->pdf_key);
        }

        $model->delete();

        return ApiResponse::success(null, 'Sertifikat dihapus');
    }

    protected function ensureEnabled(Organization $org): ?JsonResponse
    {
        if (! $this->gate->allows($org, 'certificate_generator')) {
            return ApiResponse::error(
                'Generator sertifikat tidak tersedia di paketmu.',
                ['feature' => 'certificate_generator'],
                403,
            );
        }

        return null;
    }

    protected function find(Organization $org, string $id): Certificate
    {
        return $org->certificates()->where('id', $id)->firstOrFail();
    }

    protected function findEvent(Request $request, string $id): Event
    {
        return $this->org($request)->events()->where('id', $id)->firstOrFail();
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }
}
