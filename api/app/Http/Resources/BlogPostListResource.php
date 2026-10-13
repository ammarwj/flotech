<?php

namespace App\Http\Resources;

use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Card shape: everything a list needs and nothing more.
 *
 * `body` is deliberately absent. A blog index renders twelve articles, and
 * shipping twelve full article bodies to draw twelve excerpts is the same
 * mistake `PublicEventListResource` exists to avoid.
 *
 * @mixin BlogPost
 */
class BlogPostListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'cover_url' => $this->cover_url,
            'author_name' => $this->author_name,
            'read_minutes' => (int) $this->read_minutes,
            'is_featured' => (bool) $this->is_featured,
            'views' => (int) $this->views,
            'published_at' => $this->published_at?->toIso8601String(),
            'category' => $this->whenLoaded(
                'category',
                fn () => new BlogCategoryResource($this->category)
            ),
        ];
    }
}
