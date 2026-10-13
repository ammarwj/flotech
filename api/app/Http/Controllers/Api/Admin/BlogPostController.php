<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogPostRequest;
use App\Http\Resources\BlogPostListResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use App\Services\BlogPostService;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogPostController extends Controller
{
    public function __construct(protected BlogPostService $posts) {}

    /**
     * Every article, drafts and scheduled ones included — the public endpoint
     * is the only one that filters on published_at. Same split as
     * `is_active` on /admin/testimonials.
     */
    public function index(Request $request): JsonResponse
    {
        $page = BlogPost::query()
            ->with('category')
            ->when($request->query('category'), fn ($q, $slug) => $q->whereHas(
                'category',
                fn ($c) => $c->where('slug', $slug)
            ))
            ->when($request->query('status') === 'draft', fn ($q) => $q->whereNull('published_at'))
            ->when($request->query('status') === 'published', fn ($q) => $q->published())
            ->when($request->query('status') === 'scheduled', fn ($q) => $q
                ->whereNotNull('published_at')
                ->where('published_at', '>', now()))
            ->when($request->query('search'), fn ($q, $term) => Search::anyColumn(
                $q, ['title', 'excerpt'], (string) $term
            ))
            // Drafts have no published_at, so they would sort last on that
            // column alone — and a draft is exactly what the admin just came
            // back to finish. created_at is the tiebreaker that keeps them up top.
            ->orderByRaw('case when published_at is null then 0 else 1 end')
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return ApiResponse::success([
            'items' => BlogPostListResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(BlogPost $blogPost): JsonResponse
    {
        return ApiResponse::success(new BlogPostResource($blogPost->load('category')));
    }

    public function store(BlogPostRequest $request): JsonResponse
    {
        $post = $this->posts->create($request->validated(), $request->user());

        return ApiResponse::success(
            new BlogPostResource($post->load('category')),
            'Artikel dibuat',
            201
        );
    }

    public function update(BlogPostRequest $request, BlogPost $blogPost): JsonResponse
    {
        $post = $this->posts->update($blogPost, $request->validated());

        return ApiResponse::success(
            new BlogPostResource($post->load('category')),
            'Artikel diperbarui'
        );
    }

    public function destroy(BlogPost $blogPost): JsonResponse
    {
        $blogPost->delete();

        return ApiResponse::success(null, 'Artikel dihapus');
    }
}
