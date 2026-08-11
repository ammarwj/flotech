<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_super_admin_can_save_contact_and_socials(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/site-settings', [
                'contact_email' => 'halo@floevent.id',
                'contact_phone' => '+62 812-3456-7890',
                'sales_email' => 'sales@floevent.id',
                'social_links' => ['instagram' => 'https://instagram.com/floevent'],
            ])
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'halo@floevent.id')
            ->assertJsonPath('data.contact_phone', '+62 812-3456-7890')
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/floevent');

        $this->getJson('/api/v1/site-settings')
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'halo@floevent.id');
    }

    /**
     * Compare the three shapes people actually type in one request: a bare
     * handle, a bare domain, and a full URL. Asserting only that a value was
     * stored would pass even if normalization never ran.
     */
    public function test_social_handles_are_normalized_into_profile_urls(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/site-settings', [
                'social_links' => [
                    'instagram' => '@floevent',
                    'tiktok' => 'tiktok.com/@floevent',
                    'x' => 'https://x.com/floevent',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/floevent')
            ->assertJsonPath('data.social_links.tiktok', 'https://tiktok.com/@floevent')
            ->assertJsonPath('data.social_links.x', 'https://x.com/floevent');
    }

    public function test_only_one_row_is_ever_written(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->putJson('/api/v1/admin/site-settings', ['contact_email' => 'satu@floevent.id'])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->putJson('/api/v1/admin/site-settings', ['contact_email' => 'dua@floevent.id'])
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'dua@floevent.id');

        $this->assertSame(1, SiteSetting::count());
    }

    /**
     * The two payloads are deliberately different shapes: the footer iterates
     * what it receives, the admin form binds to a fixed set of inputs.
     */
    public function test_public_payload_drops_empty_platforms_but_admin_keeps_them(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/site-settings', [
                'social_links' => ['instagram' => '@floevent'],
            ])
            ->assertOk()
            // Admin: all five keys, null for the blanks.
            ->assertJsonCount(5, 'data.social_links')
            ->assertJsonPath('data.social_links.facebook', null);

        $this->getJson('/api/v1/site-settings')
            ->assertOk()
            ->assertJsonCount(1, 'data.social_links')
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/floevent')
            ->assertJsonMissingPath('data.social_links.facebook');
    }

    public function test_public_endpoint_works_without_auth_and_with_an_empty_table(): void
    {
        $this->assertSame(0, SiteSetting::count());

        $this->getJson('/api/v1/site-settings')
            ->assertOk()
            ->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.social_links', []); // {} decodes to an empty array

        // A read must never create the row.
        $this->assertSame(0, SiteSetting::count());
    }

    public function test_non_super_admin_cannot_read_or_change_site_settings(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/site-settings')->assertStatus(403);
        $this->actingAs($user, 'api')
            ->putJson('/api/v1/admin/site-settings', ['contact_email' => 'nakal@example.com'])
            ->assertStatus(403);

        $this->assertSame(0, SiteSetting::count());
    }

    public function test_invalid_email_and_link_are_rejected(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/site-settings', [
                'contact_email' => 'bukan-email',
                'social_links' => ['facebook' => 'https://'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_email', 'social_links.facebook']);
    }

    public function test_clearing_a_platform_removes_it_from_the_footer(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->putJson('/api/v1/admin/site-settings', ['social_links' => ['instagram' => '@floevent']])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->putJson('/api/v1/admin/site-settings', ['social_links' => ['instagram' => '']])
            ->assertOk();

        $this->getJson('/api/v1/site-settings')
            ->assertOk()
            ->assertJsonCount(0, 'data.social_links');
    }

    /**
     * The platform's own account belongs in the plan payment flow, not in the
     * footer every visitor loads. Asserting only that the admin can read it
     * back would pass even if the public resource leaked it too, so compare the
     * two payloads in one test.
     */
    public function test_platform_bank_details_reach_the_admin_form_but_never_the_public_footer(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/site-settings', [
                'contact_email' => 'halo@floevent.id',
                'bank_name' => 'BCA',
                'bank_code' => '014',
                'account_number' => '9998887777',
                'account_holder' => 'PT Flo Event Indonesia',
            ])
            ->assertOk()
            ->assertJsonPath('data.account_number', '9998887777');

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/site-settings')
            ->assertOk()
            ->assertJsonPath('data.bank_name', 'BCA')
            ->assertJsonPath('data.account_holder', 'PT Flo Event Indonesia');

        $this->getJson('/api/v1/site-settings')
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'halo@floevent.id')
            ->assertJsonMissingPath('data.bank_name')
            ->assertJsonMissingPath('data.account_number')
            ->assertJsonMissingPath('data.account_holder');
    }
}
