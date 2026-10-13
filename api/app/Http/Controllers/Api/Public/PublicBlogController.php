<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCategoryResource;
use App\Http\Resources\BlogPostListResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The public blog: index, article, categories.
 *
 * > **Invariant: every query here goes through `scopePublished()`.** A draft is
 * > an article somebody is still writing, and "not linked from the index" is
 * > not the same as "not reachable" — the slug is guessable and the detail
 * > route takes it straight from the URL. The scope lives on the model for
 * > exactly this reason: three readers (index, show, related) asking the same
 * > question, and the one that spells the filter out by hand is the one that
 * > ships an unfinished draft the day someone forgets `->where('published_at')`.
 */
class PublicBlogController extends Controller
{
    /** Cards per page on /blog. */
    private const PER_PAGE = 9;

    public function index(Request $request): JsonResponse
    {
        $page = BlogPost::query()
            ->published()
            ->with('category')
            ->when($request->query('category'), fn ($q, $slug) => $q->whereHas(
                'category',
                fn ($c) => $c->where('slug', $slug)
            ))
            // Search::anyColumn, not `like`: see the note on its own class —
            // plain LIKE is case-sensitive on Postgres only, so a broken search
            // box passes the whole suite and fails in production.
            ->when($request->query('search'), fn ($q, $term) => Search::anyColumn(
                $q, ['title', 'excerpt'], (string) $term
            ))
            ->orderBy('published_at', 'desc')
            ->paginate(min((int) $request->query('per_page', self::PER_PAGE), 24));

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

    /**
     * Categories that actually have something to show.
     *
     * The count is of PUBLISHED posts, and the filter drops categories whose
     * count is zero — a filter chip that leads to an empty grid is a dead end
     * the visitor has to discover by clicking it.
     */
    public function categories(): JsonResponse
    {
        // whereHas, not having('posts_count'): the withCount alias is a
        // subquery in the SELECT list, which HAVING cannot reference on every
        // driver — it errors rather than returning the wrong rows, but it
        // errors in production only if the suite runs on the other one.
        $categories = BlogCategory::query()
            ->whereHas('posts', fn ($q) => $q->published())
            ->withCount(['posts' => fn ($q) => $q->published()])
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(BlogCategoryResource::collection($categories));
    }

    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::query()
            ->published()
            ->with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        // increment(), not a read-modify-write: two visitors landing together
        // would otherwise both write the same number back.
        $post->newQuery()->whereKey($post->id)->increment('views');

        return ApiResponse::success([
            'post' => new BlogPostResource($post),
            'related' => BlogPostListResource::collection($this->related($post)),
        ]);
    }

    /**
     * Up to three more articles to read next: same category first, then the
     * newest of anything else so a lone article in its category still gets a
     * footer instead of an empty strip.
     *
     * @return Collection<int, BlogPost>
     */
    private function related(BlogPost $post): Collection
    {
        $sameCategory = $post->blog_category_id
            ? BlogPost::query()
                ->published()
                ->with('category')
                ->where('blog_category_id', $post->blog_category_id)
                ->whereKeyNot($post->id)
                ->orderBy('published_at', 'desc')
                ->limit(3)
                ->get()
            : collect();

        if ($sameCategory->count() >= 3) {
            return $sameCategory;
        }

        $fill = BlogPost::query()
            ->published()
            ->with('category')
            ->whereKeyNot($post->id)
            ->whereNotIn('id', $sameCategory->pluck('id'))
            ->orderBy('published_at', 'desc')
            ->limit(3 - $sameCategory->count())
            ->get();

        return $sameCategory->concat($fill);
    }
}
