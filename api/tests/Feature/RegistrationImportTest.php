<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Bulk-import goes through the exact same write path as a single manual entry
 * (RegistrationController::store / TeamRosterService), so this file only
 * exercises what's specific to importing many rows at once: quota running
 * counters, per-row partial failure, and the per-category template shape.
 * Everything else (roster validation messages, offline-settlement fields) is
 * already covered by RegistrationTest.
 */
class RegistrationImportTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        return Organization::create([
            'name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id,
        ]);
    }

    private function eventWithCategory(
        Organization $org,
        string $participantType = 'team',
        string $sport = 'football',
        ?int $maxTeams = null,
        ?Plan $plan = null,
    ): Event {
        $plan ??= $this->planWith([
            'max_teams_per_category' => '10',
            'payment_gateway' => 'true',
        ]);

        $event = $org->events()->create([
            'plan_id' => $plan->id,
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => $sport,
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'participant_type' => $participantType,
            'tournament_format' => 'league', 'registration_fee' => 0,
            'max_teams' => $maxTeams, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    /** @param  list<list<mixed>>  $rows  Data rows only — the header is added from the columns list. */
    private function xlsxFor(EventCategory $category, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $sheet->fromArray(\App\Support\RegistrationTemplateColumns::headings($category), null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/import";
    }

    public function test_team_category_import_creates_teams_with_roster(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->eventWithCategory($org, 'team');
        $category = $event->categories->first();

        // Columns: team_name, contact_name, contact_phone, player_1_name, player_1_jersey, player_1_position, ...
        $file = $this->xlsxFor($category, [
            ['Tim A', 'Andi', '0811', 'Budi', '10', 'midfielder'],
            ['Tim B', 'Cici', '0822', 'Dedi', '7', 'forward'],
            ['Tim C', 'Eko', '0833', 'Fina', '1', 'goalkeeper'],
        ]);

        $this->actingAs($owner, 'api')
            ->post($this->importUrl($org, $event), [
                'category_id' => $category->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseCount('teams', 3);
        $this->assertDatabaseHas('teams', ['name' => 'Tim A', 'status' => 'approved', 'payment_status' => 'paid']);
        $this->assertDatabaseCount('players', 3);
        // Settled offline, same as a manual entry — nothing credited to the wallet.
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_import_respects_category_max_teams(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->eventWithCategory($org, 'team', maxTeams: 2);
        $category = $event->categories->first();

        $file = $this->xlsxFor($category, [
            ['Tim A', 'Andi', '0811', 'Budi', '10', ''],
            ['Tim B', 'Cici', '0822', 'Dedi', '7', ''],
            ['Tim C', 'Eko', '0833', 'Fina', '1', ''],
        ]);

        $response = $this->actingAs($owner, 'api')
            ->post($this->importUrl($org, $event), [
                'category_id' => $category->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 2);

        $this->assertCount(1, $response->json('data.errors'));
        $this->assertDatabaseCount('teams', 2);
    }

    public function test_import_respects_plan_max_teams_per_category(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $plan = $this->planWith(['max_teams_per_category' => '1', 'payment_gateway' => 'true']);
        $event = $this->eventWithCategory($org, 'team', plan: $plan);
        $category = $event->categories->first();

        $file = $this->xlsxFor($category, [
            ['Tim A', 'Andi', '0811', 'Budi', '10', ''],
            ['Tim B', 'Cici', '0822', 'Dedi', '7', ''],
        ]);

        $response = $this->actingAs($owner, 'api')
            ->post($this->importUrl($org, $event), [
                'category_id' => $category->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        // The plan gate must appear as a row error, not be silently dropped.
        $errors = $response->json('data.errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('paket event', $errors[0]['message']);
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_single_category_import_derives_name_from_player(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->eventWithCategory($org, 'single', 'badminton');
        $category = $event->categories->first();

        // Columns for a fixed roster: contact_name, contact_phone, player_1_name, player_1_position.
        $file = $this->xlsxFor($category, [
            ['Andi', '0811', 'Rudi Hartono', 'singles'],
        ]);

        $this->actingAs($owner, 'api')
            ->post($this->importUrl($org, $event), [
                'category_id' => $category->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('teams', ['name' => 'Rudi Hartono']);
    }

    public function test_single_category_import_reports_row_error_without_dropping_other_rows(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->eventWithCategory($org, 'single', 'badminton');
        $category = $event->categories->first();

        $file = $this->xlsxFor($category, [
            ['Andi', '0811', 'Rudi Hartono', 'not_a_real_position'],
            ['Budi', '0822', 'Susi Susanti', 'singles'],
        ]);

        $before = \App\Models\Team::count();

        $response = $this->actingAs($owner, 'api')
            ->post($this->importUrl($org, $event), [
                'category_id' => $category->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame($before + 1, \App\Models\Team::count());
        $this->assertCount(1, $response->json('data.errors'));
        $this->assertDatabaseHas('teams', ['name' => 'Susi Susanti']);
        $this->assertDatabaseMissing('teams', ['name' => 'Rudi Hartono']);
    }

    public function test_template_column_count_differs_between_team_and_single_category(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);

        $teamEvent = $this->eventWithCategory($org, 'team');
        $singleEvent = $this->eventWithCategory($org, 'single', 'badminton');

        $teamResponse = $this->actingAs($owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$teamEvent->id}/registrations/import-template?category_id={$teamEvent->categories->first()->id}")
            ->assertOk();

        $singleResponse = $this->actingAs($owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$singleEvent->id}/registrations/import-template?category_id={$singleEvent->categories->first()->id}")
            ->assertOk();

        $teamCols = count(\App\Support\RegistrationTemplateColumns::headings($teamEvent->categories->first()));
        $singleCols = count(\App\Support\RegistrationTemplateColumns::headings($singleEvent->categories->first()));

        $this->assertNotSame($teamCols, $singleCols);
        $this->assertTrue($teamResponse->headers->has('content-disposition'));
        $this->assertTrue($singleResponse->headers->has('content-disposition'));
    }
}
