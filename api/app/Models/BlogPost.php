<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogPost extends Model
{
    use HasUuids;

    protected $fillable = [
        'blog_category_id',
        'author_id',
        'author_name',
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_url',
        'published_at',
        'is_featured',
        'read_minutes',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'read_minutes' => 'integer',
            'views' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Visible to the public: published, and not in the future.
     *
     * Scheduling is the whole reason `published_at` is a timestamp rather than
     * the `is_active` boolean the other CMS tables use, so the future check is
     * load-bearing — a date alone would publish tomorrow's article today.
     *
     * @param  Builder<BlogPost>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now());
    }
}
