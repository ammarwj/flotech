<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogCategoryRequest;
use App\Http\Resources\BlogCategoryResource;
use App\Models\BlogCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BlogCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = BlogCategory::withCount('posts')->orderBy('sort_order')->get();

        return ApiResponse::success(BlogCategoryResource::collection($categories));
    }

    public function store(BlogCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->slugFor($data);

        $category = BlogCategory::create($data);

        return ApiResponse::success(new BlogCategoryResource($category), 'Kategori dibuat', 201);
    }

    public function update(BlogCategoryRequest $request, BlogCategory $blogCategory): JsonResponse
    {
        $data = $request->validated();

        // Blank slug on an update means "leave it alone", not "regenerate from
        // the new name": the old slug is already in links people have shared.
        if (blank($data['slug'] ?? null)) {
            unset($data['slug']);
        }

        $blogCategory->update($data);

        return ApiResponse::success(new BlogCategoryResource($blogCategory), 'Kategori diperbarui');
    }

    /**
     * Deleting a category keeps its articles — the FK is nullOnDelete, so they
     * fall back to uncategorised rather than disappearing with the taxonomy.
     */
    public function destroy(BlogCategory $blogCategory): JsonResponse
    {
        $blogCategory->delete();

        return ApiResponse::success(null, 'Kategori dihapus');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function slugFor(array $data): string
    {
        $source = blank($data['slug'] ?? null) ? ($data['name'] ?? '') : $data['slug'];

        return Str::slug((string) $source) ?: Str::lower(Str::random(8));
    }
}
