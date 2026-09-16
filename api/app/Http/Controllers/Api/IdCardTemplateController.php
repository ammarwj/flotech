<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdCard\StoreIdCardTemplateRequest;
use App\Http\Requests\IdCard\UpdateIdCardTemplateRequest;
use App\Http\Resources\IdCardTemplateResource;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdCardTemplateController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        // No withCount() twin of the certificate controller's `certificates`:
        // there is no issued-card table, deliberately — see the migration's
        // docblock for what that costs and why it is the right trade.
        $templates = $this->org($request)
            ->idCardTemplates()
            ->latest()
            ->get();

        return ApiResponse::success(IdCardTemplateResource::collection($templates));
    }

    /** The placeable fields, so the editor never hardcodes the list. */
    public function fields(): JsonResponse
    {
        $fields = collect((array) config('id_card.fields'))
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();

        return ApiResponse::success($fields);
    }

    public function store(StoreIdCardTemplateRequest $request, string $organization): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $template = $org->idCardTemplates()->create($request->validated());

        return ApiResponse::success(new IdCardTemplateResource($template), 'Template ID card dibuat', 201);
    }

    public function update(UpdateIdCardTemplateRequest $request, string $organization, string $template): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $model = $this->find($org, $template);
        $model->update($request->validated());

        return ApiResponse::success(new IdCardTemplateResource($model->fresh()), 'Template ID card diperbarui');
    }

    public function destroy(Request $request, string $organization, string $template): JsonResponse
    {
        $model = $this->find($this->org($request), $template);

        // Ungated, like the certificate twin: an organization whose plan lapsed
        // must still be able to clear out a template it can no longer use.
        $model->delete();

        return ApiResponse::success(null, 'Template ID card dihapus');
    }

    /**
     * Templates are org-scoped rows — no `event_id`, reused across events — so
     * this asks the org-level question. orgAllows() is monotone: once the
     * organization has run one event on a plan carrying ID cards, its templates
     * stay editable. Printing a particular event's cards stays event-keyed, in
     * IdCardController.
     */
    protected function ensureEnabled(Organization $org): ?JsonResponse
    {
        if (! $this->gate->orgAllows($org, 'id_card_generator')) {
            return ApiResponse::error(
                'Generator ID card tidak tersedia di paket event mana pun milikmu.',
                ['feature' => 'id_card_generator'],
                403,
            );
        }

        return null;
    }

    protected function find(Organization $org, string $id): IdCardTemplate
    {
        return $org->idCardTemplates()->where('id', $id)->firstOrFail();
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }
}
