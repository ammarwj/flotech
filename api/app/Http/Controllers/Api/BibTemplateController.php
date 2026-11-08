<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bib\StoreBibTemplateRequest;
use App\Http\Requests\Bib\UpdateBibTemplateRequest;
use App\Http\Resources\BibTemplateResource;
use App\Models\BibTemplate;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * e-BIB designs. Twin of IdCardTemplateController, including the split it
 * enforces: templates are org-scoped rows so this asks the org-level question,
 * while printing a particular race's bibs stays event-keyed in BibController.
 */
class BibTemplateController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        // No withCount() of printed bibs: there is no `bibs` table, and that is
        // permanent rather than deferred — a printed bib's row is the
        // `race_entries` row it came from. See the migration.
        $templates = $this->org($request)
            ->bibTemplates()
            ->latest()
            ->get();

        return ApiResponse::success(BibTemplateResource::collection($templates));
    }

    /** The placeable fields, so the editor never hardcodes the list. */
    public function fields(): JsonResponse
    {
        $fields = collect((array) config('bib.fields'))
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();

        return ApiResponse::success($fields);
    }

    public function store(StoreBibTemplateRequest $request, string $organization): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $template = $org->bibTemplates()->create($request->validated());

        return ApiResponse::success(new BibTemplateResource($template), 'Template e-BIB dibuat', 201);
    }

    public function update(UpdateBibTemplateRequest $request, string $organization, string $template): JsonResponse
    {
        $org = $this->org($request);

        if ($denied = $this->ensureEnabled($org)) {
            return $denied;
        }

        $model = $this->find($org, $template);
        $model->update($request->validated());

        return ApiResponse::success(new BibTemplateResource($model->fresh()), 'Template e-BIB diperbarui');
    }

    public function destroy(Request $request, string $organization, string $template): JsonResponse
    {
        $model = $this->find($this->org($request), $template);

        // Ungated, like both twins: an organization whose plan lapsed must
        // still be able to clear out a template it can no longer use.
        $model->delete();

        return ApiResponse::success(null, 'Template e-BIB dihapus');
    }

    /**
     * Templates are org-scoped rows — no `event_id`, reused across seasons — so
     * this asks the org-level question. orgAllows() is monotone: once the
     * organization has run one event on a plan carrying e-BIB, its templates
     * stay editable.
     *
     * Deliberately NOT also gated on `scoringOf() === 'none'` here, unlike
     * BibController: a template belongs to no event, so there is no sport to
     * ask about. An organizer who runs both a league and a fun run holds one
     * organization, and refusing to let them lay out a design because some
     * other event of theirs holds matches would be asking the wrong row.
     */
    protected function ensureEnabled(Organization $org): ?JsonResponse
    {
        if (! $this->gate->orgAllows($org, 'bib_generator')) {
            return ApiResponse::error(
                'Generator e-BIB tidak tersedia di paket event mana pun milikmu.',
                ['feature' => 'bib_generator'],
                403,
            );
        }

        return null;
    }

    protected function find(Organization $org, string $id): BibTemplate
    {
        return $org->bibTemplates()->where('id', $id)->firstOrFail();
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }
}
