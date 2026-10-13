<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function category(string $slug = 'panduan'): BlogCategory
    {
        return BlogCategory::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'sort_order' => 10,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePost(array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'author_name' => 'Tim flo-event',
            'title' => 'Cara membuat turnamen',
            'slug' => 'cara-membuat-turnamen',
            'excerpt' => 'Panduan singkat.',
            'body' => '<p>Isi artikel.</p>',
            'published_at' => now()->subDay(),
            'read_minutes' => 1,
        ], $overrides));
    }

    // ---- Public visibility ----

    /**
     * The three states the public endpoint must separate. A draft and a
     * scheduled post both have "no business being visible", but for different
     * reasons, and a filter that only checks `whereNotNull` lets tomorrow's
     * article out today.
     */
    public function test_public_index_shows_only_published_posts(): void
    {
        $this->makePost(['slug' => 'terbit', 'title' => 'Terbit', 'published_at' => now()->subDay()]);
        $this->makePost(['slug' => 'draf', 'title' => 'Draf', 'published_at' => null]);
        $this->makePost(['slug' => 'terjadwal', 'title' => 'Terjadwal', 'published_at' => now()->addWeek()]);

        $slugs = collect(
            $this->getJson('/api/v1/blog')->assertOk()->json('data.items')
        )->pluck('slug');

        $this->assertEqualsCanonicalizing(['terbit'], $slugs->all());
    }

    /**
     * Hiding a draft from the index is not the same as making it unreachable:
     * the slug sits in the URL and is guessable from the title.
     */
    public function test_public_detail_404s_for_draft_and_scheduled_posts(): void
    {
        $this->makePost(['slug' => 'terbit', 'published_at' => now()->subDay()]);
        $this->makePost(['slug' => 'draf', 'published_at' => null]);
        $this->makePost(['slug' => 'terjadwal', 'published_at' => now()->addWeek()]);

        $this->getJson('/api/v1/blog/terbit')->assertOk();
        $this->getJson('/api/v1/blog/draf')->assertNotFound();
        $this->getJson('/api/v1/blog/terjadwal')->assertNotFound();
    }

    public function test_public_index_filters_by_category_and_search(): void
    {
        $panduan = $this->category('panduan');
        $tips = $this->category('tips');

        $this->makePost(['slug' => 'a', 'title' => 'Jadwal otomatis', 'blog_category_id' => $panduan->id]);
        $this->makePost(['slug' => 'b', 'title' => 'Klasemen', 'blog_category_id' => $tips->id]);

        $byCategory = $this->getJson('/api/v1/blog?category=panduan')->assertOk()->json('data.items');
        $this->assertSame(['a'], collect($byCategory)->pluck('slug')->all());

        // Lowercased needle against a capitalised title: plain LIKE is
        // case-sensitive on Postgres, so this is the assertion that would
        // catch a search box that works locally and fails in production.
        $bySearch = $this->getJson('/api/v1/blog?search=klasemen')->assertOk()->json('data.items');
        $this->assertSame(['b'], collect($bySearch)->pluck('slug')->all());
    }

    /** A card list must not ship five article bodies to draw five excerpts. */
    public function test_public_index_omits_body_but_detail_includes_it(): void
    {
        $this->makePost();

        $item = $this->getJson('/api/v1/blog')->assertOk()->json('data.items.0');
        $this->assertArrayNotHasKey('body', $item);

        $this->getJson('/api/v1/blog/cara-membuat-turnamen')
            ->assertOk()
            ->assertJsonPath('data.post.body', '<p>Isi artikel.</p>');
    }

    /**
     * A category chip that leads to an empty grid is a dead end the visitor
     * only discovers by clicking it — so the count is of published posts, and
     * zero means the category is not offered at all.
     */
    public function test_public_categories_count_published_posts_only(): void
    {
        $panduan = $this->category('panduan');
        $kosong = $this->category('kosong');

        $this->makePost(['slug' => 'a', 'blog_category_id' => $panduan->id, 'published_at' => now()->subDay()]);
        $this->makePost(['slug' => 'b', 'blog_category_id' => $panduan->id, 'published_at' => null]);
        $this->makePost(['slug' => 'c', 'blog_category_id' => $kosong->id, 'published_at' => null]);

        $categories = $this->getJson('/api/v1/blog/categories')->assertOk()->json('data');

        $this->assertSame(['panduan'], collect($categories)->pluck('slug')->all());
        $this->assertSame(1, $categories[0]['posts_count']);
    }

    public function test_related_posts_exclude_the_article_itself_and_drafts(): void
    {
        $panduan = $this->category('panduan');

        $this->makePost(['slug' => 'utama', 'blog_category_id' => $panduan->id]);
        $this->makePost(['slug' => 'sekategori', 'blog_category_id' => $panduan->id]);
        $this->makePost(['slug' => 'draf', 'blog_category_id' => $panduan->id, 'published_at' => null]);

        $related = $this->getJson('/api/v1/blog/utama')->assertOk()->json('data.related');

        $this->assertSame(['sekategori'], collect($related)->pluck('slug')->all());
    }

    public function test_viewing_an_article_increments_its_view_count(): void
    {
        $post = $this->makePost();

        $this->getJson('/api/v1/blog/cara-membuat-turnamen')->assertOk();
        $this->getJson('/api/v1/blog/cara-membuat-turnamen')->assertOk();

        $this->assertSame(2, (int) $post->refresh()->views);
    }

    // ---- Admin CRUD ----

    public function test_super_admin_can_create_a_post(): void
    {
        $category = $this->category();
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/blog-posts', [
                'title' => 'Format Swiss',
                'body' => '<p>Penjelasan format Swiss.</p>',
                'blog_category_id' => $category->id,
                'published_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'format-swiss')
            // Derived, not typed: an admin should not have to retype the first
            // paragraph just to publish.
            ->assertJsonPath('data.excerpt', 'Penjelasan format Swiss.')
            ->assertJsonPath('data.author_name', $admin->full_name);

        $this->assertDatabaseHas('blog_posts', ['slug' => 'format-swiss']);
    }

    /** Platform-wide unique, unlike event slugs which are scoped to an org. */
    public function test_duplicate_titles_get_distinct_slugs(): void
    {
        $admin = $this->superAdmin();

        foreach ([1, 2] as $_) {
            $this->actingAs($admin, 'api')->postJson('/api/v1/admin/blog-posts', [
                'title' => 'Panduan Tiket',
                'body' => '<p>Isi.</p>',
            ])->assertCreated();
        }

        $this->assertEqualsCanonicalizing(
            ['panduan-tiket', 'panduan-tiket-1'],
            BlogPost::pluck('slug')->all()
        );
    }

    /**
     * > The write path is the only sanitiser, so the stored column is what
     * > matters — asserting the response alone would pass even if the resource
     * > were the thing doing the cleaning and the DB still held the script.
     */
    public function test_script_tags_are_stripped_before_the_body_is_stored(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/blog-posts', [
                'title' => 'Artikel',
                'body' => '<p>Aman</p><script>alert(1)</script><p onclick="steal()">Klik</p>',
            ])
            ->assertCreated();

        $stored = (string) BlogPost::first()->body;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<p>Aman</p>', $stored);
    }

    /**
     * Not cosmetic: a bare target="_blank" hands the opened tab a live
     * `window.opener` back into flo-event. The editor sets rel itself, but an
     * article pasted in as HTML — or posted by any other client — would not,
     * so the guarantee has to hold at the write path rather than in the
     * toolbar.
     */
    public function test_outbound_links_are_given_target_and_rel(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/blog-posts', [
                'title' => 'Artikel',
                'body' => '<p><a href="https://contoh.test">tautan</a></p>',
            ])
            ->assertCreated();

        $stored = (string) BlogPost::first()->body;

        $this->assertStringContainsString('target="_blank"', $stored);
        $this->assertStringContainsString('noopener', $stored);
    }

    /** javascript: survives neither the editor's check nor the server's. */
    public function test_javascript_urls_do_not_survive_sanitising(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/blog-posts', [
                'title' => 'Artikel',
                'body' => '<p><a href="javascript:alert(1)">klik</a></p>',
            ])
            ->assertCreated();

        $this->assertStringNotContainsString('javascript:', (string) BlogPost::first()->body);
    }

    public function test_updating_the_body_recomputes_reading_time(): void
    {
        $post = $this->makePost(['read_minutes' => 1]);
        $long = '<p>'.str_repeat('kata ', 900).'</p>';

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/blog-posts/{$post->id}", ['body' => $long])
            ->assertOk();

        $this->assertSame(5, (int) $post->refresh()->read_minutes);
    }

    /**
     * Compare the two directions in one test: an omitted key must keep the
     * stored value, while an explicit null must clear it. Asserting only the
     * first passes even if update() had stopped distinguishing them.
     */
    public function test_update_keeps_omitted_fields_and_clears_explicit_nulls(): void
    {
        $post = $this->makePost([
            'cover_url' => 'https://cdn.test/a.webp',
            'published_at' => now()->subDay(),
        ]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/blog-posts/{$post->id}", ['title' => 'Judul baru'])
            ->assertOk();

        $post->refresh();
        $this->assertSame('https://cdn.test/a.webp', $post->cover_url);
        $this->assertNotNull($post->published_at);

        // Unpublishing back to a draft is a null, not a missing key.
        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/blog-posts/{$post->id}", ['published_at' => null])
            ->assertOk();

        $this->assertNull($post->refresh()->published_at);
        $this->getJson('/api/v1/blog/cara-membuat-turnamen')->assertNotFound();
    }

    /** Admin lists drafts; the public endpoint is the only filtered reader. */
    public function test_admin_index_includes_drafts(): void
    {
        $this->makePost(['slug' => 'terbit']);
        $this->makePost(['slug' => 'draf', 'published_at' => null]);

        $slugs = collect(
            $this->actingAs($this->superAdmin(), 'api')
                ->getJson('/api/v1/admin/blog-posts')
                ->assertOk()
                ->json('data.items')
        )->pluck('slug');

        $this->assertEqualsCanonicalizing(['terbit', 'draf'], $slugs->all());
    }

    /**
     * Deleting a taxonomy must not delete the writing filed under it — the FK
     * is nullOnDelete, the same precedent as retiring a plan without removing
     * the orders that point at it.
     */
    public function test_deleting_a_category_keeps_its_posts(): void
    {
        $category = $this->category();
        $post = $this->makePost(['blog_category_id' => $category->id]);

        $this->actingAs($this->superAdmin(), 'api')
            ->deleteJson("/api/v1/admin/blog-categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseHas('blog_posts', ['id' => $post->id]);
        $this->assertNull($post->refresh()->blog_category_id);
        $this->getJson('/api/v1/blog/cara-membuat-turnamen')->assertOk();
    }

    public function test_category_slug_must_be_unique(): void
    {
        $this->category('panduan');

        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/blog-categories', ['name' => 'Panduan Lain', 'slug' => 'panduan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_regular_user_cannot_manage_the_blog(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $post = $this->makePost();

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/blog-posts')->assertForbidden();
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/admin/blog-posts', ['title' => 'X', 'body' => '<p>Y</p>'])
            ->assertForbidden();
        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/admin/blog-posts/{$post->id}")
            ->assertForbidden();

        // But reading the published blog needs no account at all.
        $this->getJson('/api/v1/blog')->assertOk();
    }
}
