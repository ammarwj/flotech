<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Certificate\StoreCertificateTemplateRequest;
use App\Http\Requests\Certificate\UpdateCertificateTemplateRequest;
use App\Http\Resources\CertificateTemplateResource;
use App\Models\CertificateTemplate;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateTemplateController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $templates = $this->org($request)
            ->certificateTemplates()
            ->withCount('certificates')
            ->latest()
            ->get();

        return ApiResponse::success(CertificateTemplateResource::collection($templates));
    }

    /** The placeable fields, so the editor never hardcodes the list. */
    public function fields(): JsonResponse
    {
        $fields = collect((array) config('certificate.fields'))
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();

        return ApiResponse::success($fields);
    }

    public function store(StoreCertificateTemplateRequest $request, string $organization): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $template = $org->certificateTemplates()->create($request->validated());

        return ApiResponse::success(new CertificateTemplateResource($template), 'Template sertifikat dibuat', 201);
    }

    public function update(UpdateCertificateTemplateRequest $request, string $organization, string $template): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $model = $this->find($org, $template);
        $model->update($request->validated());

        return ApiResponse::success(new CertificateTemplateResource($model->fresh()), 'Template sertifikat diperbarui');
    }

    public function destroy(Request $request, string $organization, string $template): JsonResponse
    {
        $model = $this->find($this->org($request), $template);

        // Issued certificates keep their rendered PDF, so losing the template
        // costs nothing — the foreign key just goes null.
        $model->delete();

        return ApiResponse::success(null, 'Template sertifikat dihapus');
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

    protected function find(Organization $org, string $id): CertificateTemplate
    {
        return $org->certificateTemplates()->where('id', $id)->firstOrFail();
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }
}
