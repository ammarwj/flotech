<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Designing an ID card: the org-scoped template row and the two shapes its
 * `fields` array can hold.
 *
 * Every case compares two states, the house rule. The gate case in particular
 * needs both halves in one test: a gate that always allows and a gate that
 * always refuses each pass one half on their own.
 */
class IdCardTemplateTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner, string $name = 'Org'): Organization
    {
        return $this->orgFor($owner, $name);
    }

    /**
     * A valid template payload, so each test only spells out what it varies.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Kartu Panitia',
            'background_url' => 'https://cdn.example.test/id-cards/bg.webp',
            'width_mm' => 85.6,
            'height_mm' => 54,
            'fields' => [
                ['key' => 'photo', 'x' => 6, 'y' => 8, 'w' => 28, 'h' => 62, 'fit' => 'cover', 'radius' => 8],
                ['key' => 'name', 'x' => 40, 'y' => 30, 'size' => 5, 'align' => 'left'],
            ],
            ...$overrides,
        ];
    }

    public function test_a_plan_with_the_feature_can_create_a_template_and_one_without_cannot(): void
    {
        $user = User::factory()->create();

        // Same owner, same payload, same route — the only difference between the
        // two organizations is the plan their one event runs on. That is what
        // makes the pair of status codes mean something.
        $allowed = $this->org($user, 'Boleh');
        $this->eventOn($allowed);

        $refused = $this->org($user, 'Tidak');
        $this->eventOn($refused, $this->planWith(['certificate_generator' => 'true']));

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$allowed->id}/id-card-templates", $this->payload())
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$refused->id}/id-card-templates", $this->payload())
            ->assertForbidden()
            ->assertJsonPath('errors.feature', 'id_card_generator');

        $this->assertSame(1, $allowed->idCardTemplates()->count());
        $this->assertSame(0, $refused->idCardTemplates()->count());
    }

    public function test_a_photo_field_needs_a_box_and_a_text_field_needs_a_size(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->eventOn($org);

        $url = "/api/v1/organizations/{$org->id}/id-card-templates";

        // The two shapes share one array, so the wildcard rules have to let both
        // `size` and `w` be null. Swapping the two fields' extras is therefore a
        // payload that passes every flat rule and is still nonsense.
        $this->actingAs($user, 'api')
            ->postJson($url, $this->payload([
                'fields' => [
                    ['key' => 'photo', 'x' => 6, 'y' => 8, 'size' => 5],
                    ['key' => 'name', 'x' => 40, 'y' => 30, 'w' => 20, 'h' => 10],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'fields.0.w', 'fields.0.h', 'fields.0.size',
                'fields.1.size', 'fields.1.w', 'fields.1.h',
            ]);

        // The same payload with each field carrying its own extras is accepted,
        // which is what proves the rejection above was about the pairing and not
        // about the fields being present at all.
        $this->actingAs($user, 'api')
            ->postJson($url, $this->payload())
            ->assertCreated();
    }

    public function test_card_size_round_trips_in_millimetres(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->eventOn($org);

        $created = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/id-card-templates", $this->payload())
            ->assertCreated();

        // 85.6 is the value a `decimal:2` cast would hand back as the string
        // "85.60". assertSame is what catches it — the renderer divides this by
        // 25.4, and there is no issued-card row to notice a card that came out
        // the wrong size.
        //
        // The heights are `int`, not `54.0`, and that is JSON rather than the
        // cast: a float with nothing after the point encodes as `54` and decodes
        // as an int. A `decimal:2` cast would still fail here — it sends the
        // string "54.00".
        $this->assertSame(85.6, $created->json('data.width_mm'));
        $this->assertSame(54, $created->json('data.height_mm'));

        $id = $created->json('data.id');

        $patched = $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/id-card-templates/{$id}", [
                'width_mm' => 105,
                'height_mm' => 148,
            ])
            ->assertOk();

        // Two sizes, not one: a resource that hardcoded CR80 would pass the first
        // pair on its own.
        $this->assertSame(105, $patched->json('data.width_mm'));
        $this->assertSame(148, $patched->json('data.height_mm'));

        // And the fields the PATCH never mentioned are still there — `sometimes`
        // means "leave it alone", not "clear it".
        $this->assertCount(2, $patched->json('data.fields'));
    }
}
