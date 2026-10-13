<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Str;

/**
 * The only thing allowed to write a blog post.
 *
 * > **Invariant: `blog_posts.body` only ever holds sanitised HTML.**
 * > That is what lets BlogPostResource ship it verbatim and the article page
 * > render it with dangerouslySetInnerHTML. A second write path that skips
 * > HtmlSanitizer would not fail anywhere — the row saves, the page renders,
 * > and the only difference is that one article now runs the author's script
 * > in every visitor's browser. Same shape as the WalletService rule: one
 * > door, so the guard cannot be forgotten at the second one.
 *
 * `excerpt` and `read_minutes` are derived from the body when the admin leaves
 * them blank, for the same reason team names are derived in
 * TeamRosterService::syncPlayers() — deriving in the controller would mean
 * deriving it again in the next caller.
 */
class BlogPostService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): BlogPost
    {
        $body = HtmlSanitizer::clean((string) ($data['body'] ?? ''));

        return BlogPost::create([
            'blog_category_id' => $data['blog_category_id'] ?? null,
            'author_id' => $author->id,
            // Snapshot, not a join: the byline must survive the account being
            // deleted, exactly like the bank details snapshotted on withdrawals.
            'author_name' => $data['author_name'] ?? $author->full_name,
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($data['slug'] ?? $data['title']),
            'excerpt' => $this->excerpt($data['excerpt'] ?? null, $body),
            'body' => $body,
            'cover_url' => $data['cover_url'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'read_minutes' => HtmlSanitizer::readMinutes($body),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BlogPost $post, array $data): BlogPost
    {
        $patch = [];

        foreach (['blog_category_id', 'title', 'cover_url', 'published_at', 'author_name'] as $key) {
            // array_key_exists, not isset: null is a meaningful value for every
            // one of these (uncategorise, unpublish, drop the cover).
            if (array_key_exists($key, $data)) {
                $patch[$key] = $data[$key];
            }
        }

        if (array_key_exists('is_featured', $data)) {
            $patch['is_featured'] = (bool) $data['is_featured'];
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $patch['slug'] = $this->uniqueSlug($data['slug'], $post->id);
        }

        if (array_key_exists('body', $data)) {
            $body = HtmlSanitizer::clean((string) $data['body']);
            $patch['body'] = $body;
            $patch['read_minutes'] = HtmlSanitizer::readMinutes($body);
        }

        if (array_key_exists('excerpt', $data)) {
            $patch['excerpt'] = $this->excerpt(
                $data['excerpt'],
                $patch['body'] ?? $post->body
            );
        }

        $post->update($patch);

        return $post->refresh();
    }

    /**
     * Platform-wide unique, unlike event slugs which are scoped to an org:
     * articles live at /blog/{slug} with nothing above them in the path.
     */
    public function uniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 1;

        while (
            BlogPost::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Blank excerpt = derive one from the body; the admin should not have to
     * retype the first paragraph to publish.
     */
    private function excerpt(?string $given, string $body): string
    {
        $given = trim((string) $given);

        if ($given !== '') {
            return Str::limit($given, 300, '');
        }

        return Str::limit(HtmlSanitizer::toText($body), 200);
    }
}
