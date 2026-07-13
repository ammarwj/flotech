<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    /**
     * Organizations the authenticated user owns or belongs to.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        $orgs = Organization::with('plan.features')
            ->where('owner_id', $user->id)
            ->orWhereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->get();

        return ApiResponse::success(OrganizationResource::collection($orgs));
    }

    /**
     * Onboarding: create an organization owned by the current user and
     * assign a plan (defaults to the free plan).
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $user = auth('api')->user();

        $planId = $request->input('plan_id') ?? Plan::where('slug', 'free')->value('id');

        $org = Organization::create([
            'name' => $request->string('name'),
            'slug' => $this->uniqueSlug($request->input('slug') ?: $request->string('name')),
            'description' => $request->input('description'),
            'contact_email' => $request->input('contact_email') ?? $user->email,
            'contact_phone' => $request->input('contact_phone'),
            'owner_id' => $user->id,
            'plan_id' => $planId,
        ]);

        $org->members()->create([
            'user_id' => $user->id,
            'role' => 'admin',
            'invited_by' => $user->id,
        ]);

        return ApiResponse::success(new OrganizationResource($org->load('plan.features')), 'Organisasi dibuat', 201);
    }

    public function show(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return ApiResponse::success(new OrganizationResource($org->load('plan.features')));
    }

    /**
     * Organizer settings: profile, branding & contact details.
     *
     * Restricted to owner/admin by the `org.admin` middleware — the slug is the
     * public event-page URL, so an `operator` must not be able to move it.
     */
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $org->update($request->safe()->only([
            'name',
            'slug',
            'logo_url',
            'banner_url',
            'description',
            'contact_email',
            'contact_phone',
            'social_links',
        ]));

        return ApiResponse::success(
            new OrganizationResource($org->load('plan.features')),
            'Pengaturan organisasi disimpan'
        );
    }

    /**
     * Assign / switch the plan for an organization (free switch or post-payment).
     */
    public function assignPlan(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
        ]);

        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        $org->update(['plan_id' => $request->input('plan_id')]);

        return ApiResponse::success(new OrganizationResource($org->load('plan.features')), 'Paket organisasi diperbarui');
    }

    protected function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
