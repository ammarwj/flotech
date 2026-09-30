<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Wallet;
use App\Support\PayoutChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payout destinations come in two kinds stored in one table, so almost every
 * test here registers a bank account AND an e-wallet and compares them:
 * asserting "the e-wallet saved" alone would still pass with the bank branch
 * validating both, which is exactly the mix-up one shared FormRequest exists to
 * prevent.
 */
class PayoutDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function create(User $user, Organization $org, array $payload)
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/bank-accounts", $payload);
    }

    /**
     * The whole feature in one comparison: the same three columns, filled from
     * two different forms. The bank keeps its typed name and its code; the
     * e-wallet stores the provider's *label* resolved from the submitted key and
     * has no code to keep. Asserting either row alone proves nothing about the
     * other reading the wrong branch.
     */
    public function test_a_bank_and_an_ewallet_store_the_same_columns_differently(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->create($user, $org, [
            'account_type' => 'bank',
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(201);

        $this->create($user, $org, [
            'account_type' => 'ewallet',
            // The key, not the label — that resolution is the request's job.
            'bank_name' => 'gopay',
            'bank_code' => '014',
            'account_number' => '081234567890',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(201);

        $bank = $org->bankAccounts()->where('account_type', 'bank')->sole();
        $ewallet = $org->bankAccounts()->where('account_type', 'ewallet')->sole();

        $this->assertSame('BCA', $bank->bank_name);
        $this->assertSame('014', $bank->bank_code);

        // The label is stored, so renaming the catalog entry tomorrow cannot
        // rewrite where past transfers went.
        $this->assertSame('GoPay', $ewallet->bank_name);
        // Sent, and dropped: there is no clearing code to route an e-wallet by.
        $this->assertNull($ewallet->bank_code);

        // Both rows carry the holder's name — the user asked for the name as
        // well as the phone number, and it is not a bank-only field.
        $this->assertSame('Budi Santoso', $ewallet->account_holder);
    }

    /** The name is required for an e-wallet too, not just the phone number. */
    public function test_an_ewallet_still_requires_the_holder_name(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->create($user, $org, [
            'account_type' => 'ewallet',
            'bank_name' => 'dana',
            'account_number' => '081234567890',
        ])->assertStatus(422)->assertJsonValidationErrors('account_holder');

        $this->assertDatabaseCount('bank_accounts', 0);
    }

    /**
     * All three shapes an Indonesian phone number gets typed in, compared in one
     * test against the one stored form. Asserting a single submission saved
     * would pass even if normalizePhone() never ran.
     */
    public function test_every_phone_shape_normalizes_to_the_same_stored_number(): void
    {
        $user = User::factory()->create();

        foreach (['+62 812-3456-7890', '6281234567890', '081234567890'] as $typed) {
            $org = $this->org($user);

            $this->create($user, $org, [
                'account_type' => 'ewallet',
                'bank_name' => 'dana',
                'account_number' => $typed,
                'account_holder' => 'Budi Santoso',
            ])->assertStatus(201);

            $this->assertSame(
                '081234567890',
                $org->bankAccounts()->sole()->account_number,
                "input: {$typed}",
            );
        }
    }

    /**
     * The two number rules, compared: a phone number is not a valid rekening
     * length-wise but digits are digits, so the pair that actually separates the
     * branches is a landline-shaped number (rejected as a phone) against the
     * same digits as a bank account (accepted).
     */
    public function test_the_number_rule_follows_the_kind(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->create($user, $org, [
            'account_type' => 'ewallet',
            'bank_name' => 'gopay',
            'account_number' => '0217654321',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(422)->assertJsonValidationErrors('account_number');

        // Same digits, other kind: a rekening has no 08 prefix rule.
        $this->create($user, $org, [
            'account_type' => 'bank',
            'bank_name' => 'BCA',
            'account_number' => '0217654321',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(201);
    }

    public function test_an_unknown_ewallet_provider_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->create($user, $org, [
            'account_type' => 'ewallet',
            'bank_name' => 'Bank Jago',
            'account_number' => '081234567890',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(422)->assertJsonValidationErrors('bank_name');
    }

    /** Omitted type means bank, matching the column default. */
    public function test_an_omitted_type_stores_a_bank_account(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->create($user, $org, [
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(201);

        $this->assertSame(PayoutChannels::TYPE_BANK, $org->bankAccounts()->sole()->account_type);
    }

    /**
     * A partial update need not resend `account_type`, so the kind has to come
     * off the stored row. Compared against the same number sent to a stored
     * *bank* row, which must be accepted — otherwise the test would pass with
     * the phone rule simply applied to everything.
     */
    public function test_a_partial_update_validates_against_the_stored_kind(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $ewallet = $org->bankAccounts()->create([
            'account_type' => 'ewallet',
            'bank_name' => 'GoPay',
            'account_number' => '081234567890',
            'account_holder' => 'Budi Santoso',
            'is_primary' => true,
        ]);
        $bank = $org->bankAccounts()->create([
            'account_type' => 'bank',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso',
        ]);

        // Digits-only, so the bank rule would have let this through silently.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/bank-accounts/{$ewallet->id}", [
                'account_number' => '0217654321',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_number');

        $this->assertSame('081234567890', $ewallet->fresh()->account_number);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/bank-accounts/{$bank->id}", [
                'account_number' => '0217654321',
            ])
            ->assertOk();

        $this->assertSame('0217654321', $bank->fresh()->account_number);
    }

    /**
     * The snapshot, compared across both kinds: a withdrawal has to record what
     * it was actually sent to, because the destination can be edited or deleted
     * afterwards. Asserting the bank case alone would pass with the column left
     * at its default.
     */
    public function test_a_withdrawal_snapshots_the_destination_kind(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['ewallet', 'gopay', '081234567890', 'GoPay'],
            ['bank', 'BCA', '1234567890', 'BCA'],
        ] as [$type, $submitted, $number, $stored]) {
            $org = $this->org($user);
            Wallet::create([
                'organization_id' => $org->id,
                'balance_available' => 500000,
                'total_earned' => 500000,
            ]);

            $this->create($user, $org, [
                'account_type' => $type,
                'bank_name' => $submitted,
                'account_number' => $number,
                'account_holder' => 'Budi Santoso',
            ])->assertStatus(201);

            $this->actingAs($user, 'api')
                ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
                ->assertStatus(201);

            $withdrawal = $org->withdrawals()->sole();
            $this->assertSame($type, $withdrawal->account_type);
            $this->assertSame($stored, $withdrawal->bank_name);
            $this->assertSame($number, $withdrawal->account_number);
        }
    }

    /** The organizer-facing resource has to say which kind it is, or the UI guesses. */
    public function test_the_resource_publishes_the_kind(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->create($user, $org, [
            'account_type' => 'ewallet',
            'bank_name' => 'ovo',
            'account_number' => '081234567890',
            'account_holder' => 'Budi Santoso',
        ])->assertStatus(201);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/bank-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.account_type', 'ewallet')
            ->assertJsonPath('data.0.bank_name', 'OVO');
    }
}
