<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Exceptions\PlanFeatureException;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Notifications\PlanOrderPaid;
use App\Notifications\PlanOrderInvoiceIssued;
use App\Support\DomainAddons;
use App\Support\DomainNames;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the lifecycle of a plan order: checkout, retrying an unpaid invoice, and
 * marking a paid one settled.
 *
 * Unlike ticket and registration payments, plan money is the platform's own
 * revenue — it never touches an organizer wallet. That holds on both rails,
 * which is why the "manual money must not touch the wallet" invariant has
 * nothing to say here.
 *
 * Numbering: an order gets its invoice number the moment it is created (an
 * unpaid invoice is still a document you can hand to finance) and its receipt
 * number only once it is actually paid.
 */
class EventPlanOrderService
{
    public function __construct(
        protected MidtransService $midtrans,
        protected PaymentRails $rails,
        protected PlanGate $gate,
        protected PaymentFeeCalculator $fees,
        protected DiscountCodeService $discounts,
    ) {}

    /**
     * Create a pending plan order and open its payment. On the gateway rail
     * that is a Snap transaction (and without Midtrans credentials the order
     * settles immediately, a dev convenience); with the gateway switched off it
     * is a manual transfer to the platform's own account.
     *
     * No period is stamped. A plan is bought once for one event, so there is
     * nothing to start or expire — what the order is waiting for is an event to
     * be spent on, not a clock.
     *
     * The organizer is the buyer here, so — same as tickets and registrations —
     * they pay gateway fee + platform margin on top of the plan price. `$channel`
     * is required exactly when resolvePayment() decides this is going out on the
     * gateway rail for a non-zero amount.
     *
     * A discount code, when given, comes off the price *here* — before
     * resolvePayment(). Gateway fees are a percentage of `$amount`
     * (PaymentFeeCalculator) and the rail itself branches on `$amount <= 0`
     * (PaymentRails::platformDestination), so discounting afterwards would
     * leave `gateway_fee` disagreeing with what Midtrans is actually asked for.
     *
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     *
     * @throws \Illuminate\Validation\ValidationException  when the code is refused
     */
    public function checkout(
        Organization $org,
        Plan $plan,
        ?string $channel = null,
        ?string $discountCode = null,
        ?array $addon = null,
    ): array {
        $discount = $this->discounts->resolve($discountCode, $org, $plan);
        $amount = $discount === null ? (float) $plan->price : $discount['amount'];

        // The add-on is priced here and folded into `amount` BEFORE
        // resolvePayment(), for the same reason the discount is: gateway fees
        // are a percentage of `$amount` and platformDestination() branches on
        // `$amount <= 0`. Adding it afterwards would charge Midtrans one number
        // and record another.
        //
        // The discount never touches it. A code is sold against a plan
        // (`discount_code_plan` is plan-keyed, and DiscountCodeService::resolve
        // takes a Plan), so letting it eat into a domain price would discount
        // something it was never scoped to — and `paidTowardsPlan()`, which
        // prices upgrades off `amount`, would start crediting domain money
        // towards a plan. `discount_amount` therefore stays computed against the
        // plan price alone, and the add-on is added on top of the discounted
        // figure.
        $quote = $this->quoteAddon($addon);
        $amount = round($amount + $quote['total'], 2);

        // Asked *before* the row exists: a refused checkout must not burn an
        // invoice number, and nextNumber() has no way to hand one back.
        $payment = $this->resolvePayment($amount, $channel);

        $order = $org->planOrders()->create([
            'plan_id' => $plan->id,
            ...$this->addonColumns($addon, $quote),
            'invoice_number' => $this->nextNumber('invoice'),
            'amount' => $amount,
            // The FK is the usage ledger; the three values beside it are a
            // snapshot, so editing or deleting the code later cannot rewrite an
            // invoice that has already gone out.
            'discount_code_id' => $discount === null ? null : $discount['code']->id,
            'discount_code' => $discount === null ? null : $discount['code']->code,
            'discount_type' => $discount === null ? null : $discount['code']->discount_type,
            'discount_value' => $discount === null ? null : $discount['code']->discount_value,
            'discount_amount' => $discount === null ? 0 : $discount['discount'],
            'status' => 'past_due', // awaiting payment; flips to paid on settlement
            'payment_method' => $payment['bank'] !== null ? 'manual' : 'gateway',
            'payment_channel' => $payment['channel'],
            'gateway_fee' => $payment['gateway_fee'],
            'gateway_tax' => $payment['gateway_tax'],
            'service_fee' => $payment['service_fee'],
            'payment_deadline_at' => $payment['bank'] !== null ? $this->rails->deadline() : null,
        ]);

        $result = $this->start($order, $payment['bank'], $payment['midtrans_payments']);

        // Only when the bill is genuinely outstanding. Without a payment gateway
        // configured openSnap() settles on the spot, and mailing "please pay"
        // about an already-paid plan would be a lie.
        if ($order->refresh()->status === 'past_due') {
            $this->mail($order, fn (EventPlanOrder $o) => new PlanOrderInvoiceIssued($o));
        }

        return $result;
    }

    /**
     * Buy a domain add-on on its own, for an event that already exists.
     *
     * The second half of "both ways": an add-on normally rides along with the
     * plan at checkout, but an organizer who decides a month in that they want
     * their own hostname should not have to buy a second plan to get one. One
     * order shape serves both — this one simply has no plan.
     *
     * `plan_id` null is what makes it an add-on-only order, and
     * scopeUnconsumed() excludes exactly that so it is never mistaken for a
     * credit. `domain_event_id` is stamped straight away rather than left for a
     * claim: the event is sitting right here, which is the whole difference from
     * the plan path.
     *
     * No discount code. Codes are plan-keyed (`discount_code_plan`) and
     * DiscountCodeService::resolve() takes a Plan, so there is nothing here for
     * one to be resolved against — the same reason `plan-orders/{order}/upgrade`
     * takes none.
     *
     * @param  array{kind: string, domain?: string|null}  $addon
     *
     * @throws PaymentException|PlanFeatureException
     */
    public function checkoutAddon(Organization $org, Event $event, array $addon, ?string $channel = null): array
    {
        if ($event->organization_id !== $org->id) {
            throw new PlanFeatureException('Event itu bukan milik organisasi ini.', ['feature' => 'domain_addon_event']);
        }

        // Refused before the row exists, so a rejected purchase burns no invoice
        // number. An outstanding bill is reopened rather than duplicated, the
        // same rule checkoutUpgrade() follows: two live bills for one domain
        // would let the organizer settle both.
        $pending = $org->planOrders()
            ->whereNotNull('domain_kind')
            ->where('domain_event_id', $event->id)
            ->where('status', 'past_due')
            ->latest()
            ->first();

        if ($pending) {
            return $this->pay($pending, $channel);
        }

        $quote = $this->quoteAddon($addon);
        $payment = $this->resolvePayment($quote['total'], $channel);

        $order = $org->planOrders()->create([
            // No plan. Every reader of an order has to tolerate this — see
            // EventPlanOrder::isAddonOnly().
            'plan_id' => null,
            ...$this->addonColumns($addon, $quote),
            'domain_event_id' => $event->id,
            'invoice_number' => $this->nextNumber('invoice'),
            'amount' => $quote['total'],
            'status' => 'past_due',
            'payment_method' => $payment['bank'] !== null ? 'manual' : 'gateway',
            'payment_channel' => $payment['channel'],
            'gateway_fee' => $payment['gateway_fee'],
            'gateway_tax' => $payment['gateway_tax'],
            'service_fee' => $payment['service_fee'],
            'payment_deadline_at' => $payment['bank'] !== null ? $this->rails->deadline() : null,
        ]);

        $result = $this->start($order, $payment['bank'], $payment['midtrans_payments']);

        if ($order->refresh()->status === 'past_due') {
            $this->mail($order, fn (EventPlanOrder $o) => new PlanOrderInvoiceIssued($o));
        }

        return $result;
    }

    /**
     * Price an add-on, or zeros when there is none.
     *
     * Goes through DomainAddons so the checkout and the price preview endpoint
     * cannot answer differently — the same arrangement as
     * DiscountCodeService::resolve() serving both the till and
     * `discount-preview`.
     *
     * Validation of the *name* is not here: that belongs to DomainService, which
     * is the only thing allowed to attach one, and it runs at activate(). What
     * is checked here is only what pricing depends on — the kind, and for a
     * registration the TLD, because an unsellable TLD prices at zero and would
     * otherwise be billed as free.
     *
     * @param  array{kind: string, domain?: string|null}|null  $addon
     * @return array{setup: float, registration: float, total: float}
     *
     * @throws PlanFeatureException
     */
    protected function quoteAddon(?array $addon): array
    {
        if ($addon === null) {
            return ['setup' => 0.0, 'registration' => 0.0, 'total' => 0.0];
        }

        $kind = $addon['kind'];

        if (! in_array($kind, DomainAddons::KINDS, true)) {
            throw new PlanFeatureException('Jenis add-on domain tidak dikenal.', ['feature' => 'domain_addon_kind']);
        }

        if ($kind === DomainAddons::KIND_REGISTER) {
            $tld = DomainAddons::tldOf((string) ($addon['domain'] ?? ''));

            if (! array_key_exists($tld, DomainAddons::REGISTRABLE_TLDS)) {
                throw new PlanFeatureException(
                    'Ekstensi domain itu belum dijual di flo-event.',
                    ['feature' => 'domain_addon_tld'],
                );
            }
        }

        return DomainAddons::quote($kind, $addon['domain'] ?? null);
    }

    /**
     * The add-on columns an order row carries, prices snapshotted.
     *
     * Spelled once because three call sites write them (plan checkout, add-on
     * checkout, and the event-creation claim reads them back) and a second copy
     * is how one of them starts storing a price the invoice does not print.
     *
     * @param  array{kind: string, domain?: string|null}|null  $addon
     * @param  array{setup: float, registration: float, total: float}  $quote
     * @return array<string, mixed>
     */
    protected function addonColumns(?array $addon, array $quote): array
    {
        if ($addon === null) {
            return [];
        }

        return [
            'domain_kind' => $addon['kind'],
            // Normalised at storage time, so the invoice, the certificate and
            // the routing map all name the same string. A subdomain is stored
            // as its full hostname for the reason given in the migration; the
            // label is written to the event, not to the order.
            'domain_name' => $this->addonHost($addon),
            'domain_setup_amount' => $quote['setup'],
            'domain_register_amount' => $quote['registration'],
            'domain_addon_amount' => $quote['total'],
        ];
    }

    /**
     * The hostname an add-on is for, as stored on the order.
     *
     * Null is legitimate for `register`: an organizer may buy "a .id domain" and
     * tell us the name later, or ask us to pick one. The super admin fills it in
     * when they fulfil the order.
     *
     * @param  array{kind: string, domain?: string|null}  $addon
     */
    protected function addonHost(array $addon): ?string
    {
        $raw = trim((string) ($addon['domain'] ?? ''));

        if ($raw === '') {
            return null;
        }

        return $addon['kind'] === DomainAddons::KIND_SUBDOMAIN
            ? DomainNames::hostFor(DomainNames::normalizeLabel($raw))
            : DomainService::normalize($raw);
    }

    /**
     * Raise a top-up bill that moves `$order` onto a bigger plan.
     *
     * The organizer pays the difference, so buying Starter and upgrading costs
     * exactly what buying Pro would have — there is no cheaper route in, and no
     * reason to sit on a plan that has stopped fitting.
     *
     * The difference is measured against everything paid so far along the
     * upgrade chain (see paidTowardsPlan()), not against this order's own
     * `amount`. Two upgrades in a row would otherwise overcharge: the second
     * would be priced against the first top-up alone. An order created by
     * `events:backfill-plan` contributes 0 and therefore pays full price, which
     * is right — no money ever changed hands for it.
     *
     * @throws PlanFeatureException
     */
    public function checkoutUpgrade(EventPlanOrder $order, Plan $target, ?string $channel = null): array
    {
        if ($order->status !== 'paid') {
            throw new PlanFeatureException('Paket ini belum lunas, jadi belum bisa di-upgrade.', ['feature' => 'plan_upgrade_unpaid']);
        }

        if ($order->isSuperseded()) {
            throw new PlanFeatureException('Paket ini sudah di-upgrade sebelumnya.', ['feature' => 'plan_already_upgraded']);
        }

        if ($order->plan_id === $target->id) {
            throw new PlanFeatureException('Event ini sudah memakai paket tersebut.', ['feature' => 'plan_unchanged']);
        }

        // Invariant 1: the target has to grant everything the current plan does.
        // This is what refuses a downgrade — and it also refuses a *dearer* plan
        // that happens to drop a feature, which a price comparison would wave
        // through. See PlanGate::planCovers().
        if (! $this->gate->planCovers($order->plan, $target)) {
            throw new PlanFeatureException(
                'Paket itu tidak mencakup semua fitur paket yang sekarang, jadi bukan upgrade.',
                ['feature' => 'plan_not_superset'],
            );
        }

        $difference = round((float) $target->price - $order->paidTowardsPlan(), 2);

        if ($difference <= 0) {
            throw new PlanFeatureException('Paket itu tidak lebih mahal, jadi tidak bisa di-upgrade ke sana.', ['feature' => 'plan_not_upgrade']);
        }

        // An unpaid attempt is reopened rather than duplicated, the same reason
        // pay() exists: two live bills for one upgrade would let the organizer
        // settle both and pay twice for a single move.
        $pending = $order->upgrades()->where('status', 'past_due')->latest()->first();

        if ($pending && $pending->plan_id === $target->id) {
            return $this->pay($pending, $channel);
        }

        $payment = $this->resolvePayment($difference, $channel);

        $upgrade = $order->organization->planOrders()->create([
            'plan_id' => $target->id,
            'upgrade_of_id' => $order->id,
            'invoice_number' => $this->nextNumber('invoice'),
            'amount' => $difference,
            'status' => 'past_due',
            'payment_method' => $payment['bank'] !== null ? 'manual' : 'gateway',
            'payment_channel' => $payment['channel'],
            'gateway_fee' => $payment['gateway_fee'],
            'gateway_tax' => $payment['gateway_tax'],
            'service_fee' => $payment['service_fee'],
            'payment_deadline_at' => $payment['bank'] !== null ? $this->rails->deadline() : null,
        ]);

        $result = $this->start($upgrade, $payment['bank'], $payment['midtrans_payments']);

        if ($upgrade->refresh()->status === 'past_due') {
            $this->mail($upgrade, fn (EventPlanOrder $o) => new PlanOrderInvoiceIssued($o));
        }

        return $result;
    }

    /**
     * Retry payment for an unpaid invoice.
     *
     * Snap tokens expire (~24h), so rather than replaying the stored one we
     * open a fresh transaction under a new order id. The invoice number is
     * deliberately kept — the organizer still owes the same one bill. The
     * abandoned order id simply expires at Midtrans; a late webhook for it
     * finds no row and 404s, which is harmless.
     *
     * The rail is re-derived rather than replayed: a bill raised while the
     * gateway was up has to stay payable after a super admin switches it off,
     * and the other way round. RegistrationService::startPayment() already
     * rewrites payment_method on every retry for the same reason — the
     * snapshot rule means "never derived at read time", not "frozen while
     * unpaid". Nothing downstream of a plan order depends on the old value:
     * there is no wallet credit and no platform fee to keep consistent with it.
     *
     * The caller refuses this while a proof is under review, so a re-derive
     * can't strand one.
     *
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    public function pay(EventPlanOrder $order, ?string $channel = null): array
    {
        $payment = $this->resolvePayment((float) $order->amount, $channel);

        $order->update([
            'payment_method' => $payment['bank'] !== null ? 'manual' : 'gateway',
            'payment_channel' => $payment['channel'],
            'gateway_fee' => $payment['gateway_fee'],
            'gateway_tax' => $payment['gateway_tax'],
            'service_fee' => $payment['service_fee'],
            'payment_deadline_at' => $payment['bank'] !== null ? $this->rails->deadline() : null,
        ]);

        return $this->start($order, $payment['bank'], $payment['midtrans_payments']);
    }

    /**
     * Decide the rail for `$amount`, and — on the gateway rail for a non-zero
     * amount — the buyer-paid fee breakdown on top of it. Manual and free
     * orders get null/0s, identical to what every call site wrote by hand
     * before this existed.
     *
     * @return array{bank: ?SiteSetting, channel: ?string, gateway_fee: float, gateway_tax: float, service_fee: float, midtrans_payments: array}
     */
    protected function resolvePayment(float $amount, ?string $channel): array
    {
        $bank = $this->rails->platformDestination($amount);

        if ($bank !== null || $amount <= 0) {
            return ['bank' => $bank, 'channel' => null, 'gateway_fee' => 0.0, 'gateway_tax' => 0.0, 'service_fee' => 0.0, 'midtrans_payments' => []];
        }

        if ($channel === null) {
            throw new PaymentException('Pilih metode pembayaran.', ['payment_channel' => ['Metode pembayaran wajib dipilih.']]);
        }

        $breakdown = $this->fees->forChannel(
            $channel,
            $amount,
            PaymentFeeCalculator::AUDIENCE_ORGANIZER,
        );

        return [
            'bank' => null,
            'channel' => $breakdown['channel'],
            'gateway_fee' => $breakdown['gateway_fee'],
            'gateway_tax' => $breakdown['gateway_tax'],
            'service_fee' => $breakdown['service_fee'],
            'midtrans_payments' => $breakdown['midtrans_payments'],
        ];
    }

    /**
     * Open payment on whichever rail this order is on.
     *
     * The manual branch returns before openSnap() ever runs. That is the point:
     * `mock` means "no Midtrans server key", not "paid", and a manual order
     * reaching that branch would hand out a credit nobody paid for.
     *
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    protected function start(EventPlanOrder $order, ?SiteSetting $bank, array $enabledPayments = []): array
    {
        // Nothing left to collect — a 100% code, or a fixed one worth at least
        // the plan. Settling here rather than in checkout() covers pay() too,
        // and it has to happen before openSnap(), which would otherwise post
        // `gross_amount: 0` to Midtrans. No new client branch is needed:
        // checkoutOutcome() already reads the order's own status.
        if ($bank === null && $order->gross_amount <= 0) {
            $this->activate($order, 'discount');

            return [
                'order' => $order->load('plan.features'),
                'snap_token' => null,
                'redirect_url' => null,
                'mock' => false,
                'payment_method' => 'gateway',
                'bank_account' => null,
            ];
        }

        if ($bank === null) {
            return $this->openSnap($order, $enabledPayments);
        }

        return [
            'order' => $order->load('plan.features'),
            'snap_token' => null,
            'redirect_url' => null,
            'mock' => false,
            'payment_method' => 'manual',
            'bank_account' => $bank,
        ];
    }

    /**
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    protected function openSnap(EventPlanOrder $order, array $enabledPayments = []): array
    {
        $org = $order->organization;

        // Newly minted ids carry PLN-; ids already at Midtrans keep their SUB-
        // prefix and still settle, because MidtransWebhookController routes plan
        // orders through its `default` arm rather than matching on the prefix.
        // Adding a PLN- arm to that match would strand every outstanding SUB-.
        $orderId = 'PLN-'.Str::upper(Str::random(10));

        // gross_amount includes the fee the organizer pays on top of the plan
        // price — amount itself never does, see getGrossAmountAttribute().
        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round($order->gross_amount)],
            ['first_name' => $org->name, 'email' => $org->contact_email],
            rtrim((string) config('app.frontend_url'), '/').'/organizer/billing?status=success',
            $enabledPayments,
        );

        $order->update([
            'midtrans_order_id' => $orderId,
            'midtrans_token' => $snap['token'],
        ]);

        if ($snap['mock']) {
            // No payment gateway configured — settle immediately for dev.
            $this->activate($order);
        }

        return [
            'order' => $order->load('plan.features'),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $snap['mock'],
            'payment_method' => 'gateway',
            'bank_account' => null,
        ];
    }

    /**
     * Mark a plan order paid.
     *
     * Nothing is written to the organization: a paid order is a *credit*, and it
     * entitles nothing until an event spends it (EventController::store). The
     * event does not exist yet at this point — the organizer pays first and
     * creates the event afterwards — so there is nothing here to bind it to.
     *
     * Midtrans re-delivers webhooks, so this must be safe to run twice: an
     * already-issued receipt number is never reissued.
     */
    public function activate(EventPlanOrder $order, ?string $paymentType = null): void
    {
        // A receipt is only issued once, and so is the email carrying it. Without
        // this, a re-delivered webhook lands a second "you paid" in the inbox for
        // one payment.
        $alreadyPaid = $order->status === 'paid';

        $order->update([
            'status' => 'paid',
            'paid_at' => $order->paid_at ?? Carbon::now(),
            'receipt_number' => $order->receipt_number ?? $this->nextNumber('receipt'),
            'payment_type' => $paymentType ?? $order->payment_type,
        ]);

        if ($order->upgrade_of_id) {
            $this->applyUpgrade($order);
        }

        if ($order->hasDomainAddon()) {
            $this->applyDomainAddon($order->refresh());
        }

        if (! $alreadyPaid) {
            $this->mail($order, fn (EventPlanOrder $o) => new PlanOrderPaid($o));
        }
    }

    /**
     * Hand the entitlement over from the order being upgraded to this one.
     *
     * The successor becomes the holder — of the event if there was one, of the
     * credit if there wasn't — and the old order retires. That is what collapses
     * the two cases ("event already running" and "credit not spent yet") into
     * one path: the only difference is whether there is an `event_id` to move.
     *
     * The old order keeps its own `plan_id`, `amount`, invoice and receipt
     * untouched. Its invoice still truthfully reads "Starter, Rp 150.000"; this
     * order's reads "Pro, Rp 200.000". Nothing has to be snapshotted to keep
     * either document honest, because neither is ever rewritten.
     *
     * Runs under activate(), so it must survive a re-delivered webhook: every
     * write here is the same value the second time round.
     */
    private function applyUpgrade(EventPlanOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $previous = EventPlanOrder::whereKey($order->upgrade_of_id)->lockForUpdate()->first();

            if (! $previous) {
                return;
            }

            $eventId = $order->event_id ?? $previous->event_id;

            // Released before the claim, because `event_id` is unique: the
            // successor cannot take the event while the old row still holds it.
            // Safe to hand back to no one — scopeUnconsumed() reads the successor
            // to know this order is spent, not its own columns.
            if ($previous->event_id !== null) {
                $previous->update(['event_id' => null, 'consumed_at' => null]);
            }

            if ($eventId !== null) {
                $order->update(['event_id' => $eventId, 'consumed_at' => $order->consumed_at ?? Carbon::now()]);
                Event::whereKey($eventId)->update(['plan_id' => $order->plan_id]);
            }
        });
    }

    /**
     * Put a paid domain add-on into effect.
     *
     * The three kinds part ways here, and only here:
     *
     *  - `subdomain` — we hold the zone, so the name is ours to issue. Attached
     *    immediately; `domains:sync` runs every minute and certifies it on its
     *    next pass. Nothing waits on anyone outside: the wildcard A record
     *    already resolves, so the ACME challenge can answer at once.
     *  - `own` — the organizer holds the name. Attached immediately too, which
     *    is what publishes the port-80 vhost that the ACME challenge needs;
     *    certification then waits on their DNS, which is outside our control.
     *  - `register` — nobody holds the name yet. Left unstamped, which is what
     *    puts it in the super admin's queue (see scopeAwaitingDomainFulfilment).
     *    Attaching a name we have not bought would publish a vhost for a
     *    hostname that resolves to someone else.
     *
     * Runs under activate(), so it must survive a re-delivered webhook: every
     * write is the same value the second time round, and assign() is a no-op
     * when the name has not changed (it deliberately keeps the certificate in
     * that case, so a replay cannot knock a live domain offline).
     *
     * A failure here is logged, never rethrown. The money has moved and the
     * order is paid; bubbling a DNS or nginx problem into the Midtrans webhook
     * would provoke a retry and, worse, leave the organizer's payment unrecorded
     * over something they can fix from the domain page. Same call the mail()
     * wrapper below makes.
     */
    protected function applyDomainAddon(EventPlanOrder $order): void
    {
        // `register` waits for a human. Nothing to stamp, nothing to attach.
        if ($order->domain_kind === DomainAddons::KIND_REGISTER) {
            return;
        }

        // Fulfilled by being paid for: there is no work left for anyone. Stamped
        // rather than left null so these never show up in a queue that exists
        // for orders someone has to act on.
        $order->update(['domain_fulfilled_at' => $order->domain_fulfilled_at ?? Carbon::now()]);

        $event = $order->domainEvent;

        // Bought alongside a plan and the event does not exist yet. The claim in
        // EventController::store() carries the add-on over and attaches it
        // there — this is the ordinary path for a checkout, not an error.
        if (! $event || $order->domain_name === null) {
            return;
        }

        $this->attachDomain($event, $order);
    }

    /**
     * Hand `$order`'s domain to `$event`.
     *
     * Shared by applyDomainAddon() and the event-creation claim, so a domain
     * bought before the event existed is attached by exactly the same code as
     * one bought after — two copies would differ the day one of them forgot to
     * swallow its errors or forgot the subdomain branch.
     */
    public function attachDomain(Event $event, EventPlanOrder $order): void
    {
        if ($order->domain_name === null) {
            return;
        }

        try {
            $domains = app(DomainService::class);

            if ($order->domain_kind === DomainAddons::KIND_SUBDOMAIN) {
                // Hand it the label. assignSubdomain() writes both columns, and
                // the label is what distinguishes a name we issued from one the
                // organizer brought.
                $domains->assignSubdomain($event, $order->domain_name);

                return;
            }

            $domains->assign($event, $order->domain_name);
        } catch (Throwable $e) {
            Log::error('Gagal memasang domain add-on', [
                'plan_order_id' => $order->id,
                'event_id' => $event->id,
                'domain' => $order->domain_name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A super admin has registered (or failed to register) the name an organizer
     * bought.
     *
     * The one kind that needs a human: `own` and `subdomain` are fulfilled by
     * activate(). Attaching the domain is the same call the other two kinds
     * make, so a registered name behaves identically from here on.
     *
     * @throws PaymentException
     */
    public function fulfilDomain(EventPlanOrder $order, User $admin, ?string $domain, ?string $note): void
    {
        if (! $order->isAwaitingDomainFulfilment()) {
            throw new PaymentException('Pesanan domain ini tidak sedang menunggu registrasi.');
        }

        $order->update([
            'domain_fulfilled_at' => Carbon::now(),
            'domain_fulfilled_by' => $admin->id,
            // The name may differ from what was asked for — the requested one
            // was taken, or the organizer left it to us. Written over the
            // request rather than beside it: what matters afterwards is the name
            // they actually got, and `domain_note` carries the explanation.
            'domain_name' => $domain === null ? $order->domain_name : DomainService::normalize($domain),
            'domain_note' => $note,
        ]);

        $event = $order->refresh()->domainEvent;

        if ($event) {
            $this->attachDomain($event, $order);
        }
    }

    /**
     * The organizer uploads their transfer receipt for a super admin to check.
     *
     * @throws PaymentException
     */
    public function submitProof(EventPlanOrder $order, string $proofUrl): void
    {
        if (! $order->isManual()) {
            throw new PaymentException('Tagihan ini tidak dibayar lewat transfer manual.');
        }

        if ($order->status !== 'past_due') {
            throw new PaymentException('Tagihan ini sudah tidak menunggu pembayaran.');
        }

        $order->attachProof($proofUrl);
    }

    /**
     * Accept a manual transfer into the platform's own account, which activates
     * the plan. Approving is the whole payment — no money moves on its own —
     * so this is super_admin work, never an organizer's.
     *
     * @throws PaymentException
     */
    public function approveProof(EventPlanOrder $order, User $admin): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->markVerified($admin);
        $this->activate($order->fresh(), 'manual_transfer');
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(EventPlanOrder $order, string $reason, Carbon $deadline): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->rejectProof($reason, $deadline);
    }

    /**
     * Mail the organization's owner. Swallows its own errors: the money has moved
     * and the plan is applied, so a PDF render or queue hiccup must not bubble
     * into the Midtrans webhook and provoke a retry.
     *
     * @param  callable(EventPlanOrder): Notification  $make
     */
    protected function mail(EventPlanOrder $order, callable $make): void
    {
        try {
            $order->organization->owner?->notify(
                $make($order->load(['plan', 'organization']))
            );
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email pembelian paket', [
                'plan_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Next document number for the current month, e.g. INV/2026/07/0001.
     *
     * The sequence restarts each month. Concurrent checkouts are serialized by
     * locking the month's rows; the unique index on the column is the backstop.
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function nextNumber(string $kind): string
    {
        return app(DocumentNumberService::class)->next(
            EventPlanOrder::class,
            $kind === 'receipt' ? 'receipt_number' : 'invoice_number',
            config("billing.{$kind}_prefix", $kind === 'receipt' ? 'KW' : 'INV'),
        );
    }
}
