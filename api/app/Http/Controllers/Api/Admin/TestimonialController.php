<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestimonialRequest;
use App\Http\Resources\TestimonialResource;
use App\Models\Testimonial;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TestimonialController extends Controller
{
    public function index(): JsonResponse
    {
        // Unfiltered: the admin list shows inactive rows too. Only the public
        // endpoint filters on is_active.
        $testimonials = Testimonial::orderBy('sort_order')->get();

        return ApiResponse::success(TestimonialResource::collection($testimonials));
    }

    public function store(TestimonialRequest $request): JsonResponse
    {
        $testimonial = Testimonial::create($request->validated());

        return ApiResponse::success(new TestimonialResource($testimonial), 'Testimoni dibuat', 201);
    }

    public function update(TestimonialRequest $request, Testimonial $testimonial): JsonResponse
    {
        $testimonial->update($request->validated());

        return ApiResponse::success(new TestimonialResource($testimonial), 'Testimoni diperbarui');
    }

    public function destroy(Testimonial $testimonial): JsonResponse
    {
        $testimonial->delete();

        return ApiResponse::success(null, 'Testimoni dihapus');
    }
}
