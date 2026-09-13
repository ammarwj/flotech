<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The favicon upload and the branded email header — the two parts of the
 * branding CMS that produce a file rather than store a URL.
 */
class PlatformBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * The endpoint has to emit a real icon, not a PNG wearing an .ico name.
     *
     * Asserting the filename would pass on an encoder that never ran, so this
     * checks the ICO signature in the stored bytes: `00 00 01 00`.
     *
     * Skipped where imagick is missing rather than dropped: it is the only test
     * that proves the encoder works at all, and the extension is installed in
     * api/Dockerfile precisely so CI and production run it. A machine on an
     * older image sees the skip message and knows to rebuild.
     */
    public function test_favicon_upload_stores_a_real_ico(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('imagick is missing — rebuild the api image (docker compose build api).');
        }

        Storage::fake('public');

        $url = $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/uploads/favicon', [
                'file' => UploadedFile::fake()->image('logo.png', 512, 512),
            ])
            ->assertOk()
            ->json('data.key');

        $this->assertStringEndsWith('.ico', $url);

        $bytes = Storage::disk('public')->get($url);
        $this->assertSame("\x00\x00\x01\x00", substr($bytes, 0, 4), 'stored file must carry the ICO signature');
    }

    /**
     * Guarded, unlike the uploads/* routes beside it — those are public because
     * the public registration form posts to them. This one has no such caller,
     * so the difference is worth pinning down.
     */
    public function test_favicon_upload_is_super_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']), 'api')
            ->postJson('/api/v1/admin/uploads/favicon', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(403);
    }

    public function test_favicon_upload_rejects_a_non_image(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/uploads/favicon', [
                'file' => UploadedFile::fake()->create('surat.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /**
     * Compared with and without an uploaded logo in one test: asserting the
     * <img> appears would pass even if the text fallback had stopped working,
     * and that fallback is the whole reason the header was text to begin with
     * (mail clients block remote images).
     */
    public function test_mail_header_falls_back_to_the_wordmark_without_a_logo(): void
    {
        $user = User::factory()->create();

        $plain = (string) (new ResetPasswordNotification('token'))->toMail($user)->render();
        $this->assertStringNotContainsString('<img src="https://cdn.example.test', $plain);
        $this->assertStringContainsString('brand-accent', $plain, 'the text lockup is the fallback');

        SiteSetting::create(['logo_url' => 'https://cdn.example.test/platform/logo.webp']);

        $branded = (string) (new ResetPasswordNotification('token'))->toMail($user)->render();
        $this->assertStringContainsString('https://cdn.example.test/platform/logo.webp', $branded);
        // The brand name rides along as alt text, so a blocked image still reads
        // as the brand rather than an empty box.
        $this->assertStringContainsString('alt="'.config('brand.name').'"', $branded);
    }
}
