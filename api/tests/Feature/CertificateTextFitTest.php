<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Shrink-to-fit on the PDF side.
 *
 * Asserted on the rendered HTML, never the PDF bytes: dompdf output is
 * compressed streams, so a test holding them can only say that *something*
 * rendered — and that stays green when a name stops being shrunk, which is the
 * exact failure this feature exists to prevent.
 *
 * Every case compares two renders. A build that shrinks unconditionally passes
 * "the long name got smaller"; one that never shrinks passes "the short name
 * did not".
 */
class CertificateTextFitTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function template(Organization $org, array $fields): CertificateTemplate
    {
        return $org->certificateTemplates()->create([
            'name' => 'Juara',
            'background_url' => '',
            'orientation' => 'landscape',
            'fields' => $fields,
        ]);
    }

    private function certificate(Organization $org, string $name): Certificate
    {
        $event = $this->eventOn($org);

        return Certificate::create([
            'organization_id' => $org->id,
            'event_id' => $event->id,
            'certificate_number' => 'CERT-2026-11-'.Str::padLeft((string) random_int(1, 9999), 4, '0'),
            'recipient_type' => 'team',
            'recipient_id' => (string) Str::uuid(),
            'recipient_name' => $name,
            'award_title' => 'Juara 1',
            'issued_at' => Carbon::now(),
        ]);
    }

    /** The `font-size: Npt` the one placed field was rendered with. */
    private function sizeIn(string $html): float
    {
        $this->assertMatchesRegularExpression('/font-size:\s*([\d.]+)pt/', $html);

        preg_match('/font-size:\s*([\d.]+)pt/', $html, $m);

        return (float) $m[1];
    }

    public function test_a_long_name_is_shrunk_to_fit_its_box_while_a_short_one_is_not(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // 10% of 595pt ≈ 60pt — room for one 36pt line, not three.
        $template = $this->template($org, [
            [
                'key' => 'recipient_name', 'x' => 50, 'y' => 40, 'size' => 36,
                'align' => 'center', 'w' => 50, 'h' => 10,
            ],
        ]);

        $service = app(CertificateService::class);

        $short = $this->sizeIn($service->html(
            $this->certificate($org, 'Garuda FC'),
            $template,
            '',
        ));

        $long = $this->sizeIn($service->html(
            $this->certificate($org, 'Persatuan Sepak Bola Mahasiswa Yogyakarta Bersatu'),
            $template,
            '',
        ));

        // The pair is the test. Either assertion alone passes a build that is
        // wrong in the other direction.
        $this->assertSame(36.0, $short, 'a name that already fits must print at the size set');
        $this->assertLessThan(36.0, $long, 'a name too long for its box must be shrunk');
    }

    /**
     * A field with no box is never shrunk — the state every certificate
     * template written before this feature is in.
     *
     * Compared against the same long name WITH a box, because "it rendered"
     * says nothing: a build that shrank everything would still emit a
     * font-size, just not the one the organizer asked for.
     */
    public function test_a_field_without_a_box_renders_at_the_size_it_was_given(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $base = ['key' => 'recipient_name', 'x' => 50, 'y' => 40, 'size' => 36, 'align' => 'center'];
        $name = 'Persatuan Sepak Bola Mahasiswa Yogyakarta Bersatu';
        $service = app(CertificateService::class);

        $without = $this->sizeIn($service->html(
            $this->certificate($org, $name),
            $this->template($org, [$base]),
            '',
        ));

        $with = $this->sizeIn($service->html(
            $this->certificate($org, $name),
            $this->template($org, [[...$base, 'w' => 50, 'h' => 10]]),
            '',
        ));

        $this->assertSame(36.0, $without, 'without a box nothing should be shrunk');
        $this->assertLessThan($without, $with);
    }

    /**
     * Half a box is no box: both `w` and `h` are needed, because with no width
     * there is nothing to wrap against and with no height no limit to fit into.
     *
     * Asserted as a trio so a build that fitted on either half alone — against
     * a guessed default for the other — cannot pass.
     */
    public function test_both_halves_of_the_box_are_required_before_anything_is_fitted(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $base = ['key' => 'recipient_name', 'x' => 50, 'y' => 40, 'size' => 36, 'align' => 'center'];
        $name = 'Persatuan Sepak Bola Mahasiswa Yogyakarta Bersatu';
        $service = app(CertificateService::class);

        $render = fn (array $field) => $this->sizeIn($service->html(
            $this->certificate($org, $name),
            $this->template($org, [$field]),
            '',
        ));

        $this->assertSame(36.0, $render([...$base, 'w' => 50]), 'width alone sets no limit');
        $this->assertSame(36.0, $render([...$base, 'h' => 10]), 'height alone has nothing to wrap');
        $this->assertLessThan(36.0, $render([...$base, 'w' => 50, 'h' => 10]));
    }

    /**
     * A field with a box states its own width in the markup, and one without it
     * keeps the 2*x trick the PDF has always used to put an alignment point on
     * x without `transform` (which dompdf does not support).
     *
     * Both, because the old path still carries every template already saved:
     * replacing it would move every centred field on every existing
     * certificate, silently.
     */
    public function test_a_boxed_field_states_its_width_while_a_boxless_one_keeps_the_old_anchor(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $base = ['key' => 'recipient_name', 'x' => 50, 'y' => 40, 'size' => 24, 'align' => 'center'];
        $service = app(CertificateService::class);
        $certificate = $this->certificate($org, 'Garuda FC');

        // 40% of 842pt = 336.8pt, centred on x=50 → left 252.2pt.
        $boxed = $service->html($certificate, $this->template($org, [[...$base, 'w' => 40, 'h' => 10]]), '');

        $this->assertStringContainsString('width: 336.80pt', $boxed);
        $this->assertStringContainsString('left: 252.60pt', $boxed);

        // Boxless: a box of 2*x wide starting at 0, so its own centre is on x.
        $boxless = $service->html($certificate, $this->template($org, [$base]), '');

        $this->assertStringContainsString('left: 0pt; width: 842.00pt', $boxless);
    }
}
