<?php

namespace App\Http\Resources;

use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full article, list fields plus `body`.
 *
 * `body` is already sanitised — App\Support\HtmlSanitizer runs on write, so
 * this resource ships it as-is and the client renders it with
 * dangerouslySetInnerHTML. That is only true because nothing else may write
 * the column; see the invariant on BlogPostService.
 *
 * `published_at` is published here too, null for a draft: the admin editor
 * binds to the same shape the public page reads.
 *
 * @mixin BlogPost
 */
class BlogPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blog_category_id' => $this->blog_category_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'cover_url' => $this->cover_url,
            'author_name' => $this->author_name,
            'read_minutes' => (int) $this->read_minutes,
            'is_featured' => (bool) $this->is_featured,
            'views' => (int) $this->views,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'category' => $this->whenLoaded(
                'category',
                fn () => new BlogCategoryResource($this->category)
            ),
        ];
    }
}
