<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingContentTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function testimonial(array $overrides = []): Testimonial
    {
        return Testimonial::create(array_merge([
            'quote' => 'Sangat membantu.',
            'name' => 'Rizky Pratama',
            'role' => 'Ketua Liga Futsal Bandung',
            'initials' => 'RP',
            'avatar_preset' => 'brand',
            'rating' => 5,
            'is_active' => true,
            'sort_order' => 10,
        ], $overrides));
    }

    public function test_super_admin_can_create_testimonial(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/testimonials', [
                'quote' => 'Rekap klasemen jadi otomatis.',
                'name' => 'Sari Wulandari',
                'role' => 'Event Organizer, Surabaya',
                'initials' => 'SW',
                'avatar_preset' => 'purple',
                'rating' => 4,
                'sort_order' => 20,
            ])
            ->assertCreated()
            ->assertJsonPath('data.avatar_preset', 'purple')
            ->assertJsonPath('data.rating', 4);

        $this->assertDatabaseHas('testimonials', ['name' => 'Sari Wulandari']);
    }

    public function test_testimonial_rejects_unknown_avatar_preset(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/testimonials', [
                'quote' => 'Z',
                'name' => 'X',
                'role' => 'Y',
                'initials' => 'XY',
                'avatar_preset' => 'neon',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar_preset');
    }

    public function test_regular_user_cannot_manage_landing_content(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/testimonials')->assertStatus(403);
        $this->actingAs($user, 'api')->getJson('/api/v1/admin/faqs')->assertStatus(403);
    }

    public function test_public_testimonials_hide_inactive_and_respect_sort_order(): void
    {
        $this->testimonial(['name' => 'Kedua', 'sort_order' => 20]);
        $this->testimonial(['name' => 'Pertama', 'sort_order' => 10]);
        $this->testimonial(['name' => 'Disembunyikan', 'is_active' => false, 'sort_order' => 5]);

        $this->getJson('/api/v1/testimonials')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Pertama')
            ->assertJsonPath('data.1.name', 'Kedua');
    }

    public function test_admin_testimonials_include_inactive(): void
    {
        $this->testimonial(['name' => 'Disembunyikan', 'is_active' => false]);

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/testimonials')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_super_admin_can_update_and_delete_faq(): void
    {
        $faq = Faq::create([
            'question' => 'Paket paling murah mulai dari berapa?',
            'answer' => 'Rp 49.000/bulan.',
            'sort_order' => 10,
        ]);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/faqs/{$faq->id}", [
                'question' => 'Paket paling murah mulai dari berapa?',
                'answer' => 'Rp 59.000/bulan.',
                'is_active' => false,
                'sort_order' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Rp 59.000/bulan.');

        $this->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->superAdmin(), 'api')
            ->deleteJson("/api/v1/admin/faqs/{$faq->id}")
            ->assertOk();

        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
    }
}
