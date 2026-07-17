<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqRequest;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        // Unfiltered: the admin list shows inactive rows too. Only the public
        // endpoint filters on is_active.
        $faqs = Faq::orderBy('sort_order')->get();

        return ApiResponse::success(FaqResource::collection($faqs));
    }

    public function store(FaqRequest $request): JsonResponse
    {
        $faq = Faq::create($request->validated());

        return ApiResponse::success(new FaqResource($faq), 'FAQ dibuat', 201);
    }

    public function update(FaqRequest $request, Faq $faq): JsonResponse
    {
        $faq->update($request->validated());

        return ApiResponse::success(new FaqResource($faq), 'FAQ diperbarui');
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return ApiResponse::success(null, 'FAQ dihapus');
    }
}
