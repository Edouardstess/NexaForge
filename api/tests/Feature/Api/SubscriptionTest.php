<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Billing\SubscriptionService;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Support\Tenancy\OrgContext;
use DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SubscriptionTest extends TestCase
{
    private Organization $org;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->org = $this->makeOrganization('ti-machann', 'HTG');
        $this->makeLocation($this->org, 'DELMAS');

        $membership = $this->makeMember($this->org, 'OWNER', [], 'pwopriyete@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        $this->actingAsMember($membership);
    }

    private function price(string $code): PlanPrice
    {
        return PlanPrice::query()
            ->whereHas('plan', fn ($q) => $q->where('code', $code))
            ->sole();
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function asOwner(): string
    {
        if (! isset($this->token)) {
            app(OrgContext::class)->forget();
            $this->token = $this->postJson('/api/v1/auth/login', [
                'identifier' => 'pwopriyete@nexa.test', 'password' => 'modpas-123',
            ])->json('data.token');
        }

        return $this->token;
    }

    /* ---------------------------------------------------- l'abonnement */

    #[Test]
    public function subscribing_bills_the_period_immediately(): void
    {
        $subscription = $this->service()->subscribe($this->price('BOUTIQUE'));

        $this->assertSame('ACTIVE', $subscription->status);
        $this->assertSame(1, $subscription->quantity);
        $this->assertSame(2500, $subscription->periodAmount()->minor);

        $invoice = Invoice::query()->sole();
        $this->assertSame(2500, $invoice->total_minor);
        $this->assertSame('OPEN', $invoice->status);
    }

    #[Test]
    public function a_trial_is_not_billed(): void
    {
        // La première facture part au renouvellement, quand le commerçant a vu
        // le produit marcher.
        $subscription = $this->service()->subscribe($this->price('STARTER'), trialDays: 14);

        $this->assertSame('TRIALING', $subscription->status);
        $this->assertSame(0, Invoice::query()->count());
    }

    #[Test]
    public function the_quantity_is_counted_not_declared(): void
    {
        // Le client ne choisit pas combien de boutiques il déclare : le Multi
        // facture au site, minimum trois, et il n'y en a qu'un ici.
        $subscription = $this->service()->subscribe($this->price('MULTI'));

        $this->assertSame(3, $subscription->quantity);
        $this->assertSame(6000, $subscription->periodAmount()->minor);
    }

    #[Test]
    public function two_live_subscriptions_are_impossible(): void
    {
        $this->service()->subscribe($this->price('STARTER'));

        $this->expectException(DomainException::class);
        $this->service()->subscribe($this->price('BOUTIQUE'));
    }

    #[Test]
    public function the_database_refuses_a_second_live_subscription_even_without_the_service(): void
    {
        // L'index partiel est la vraie garantie : un check-then-insert
        // laisserait passer deux requêtes simultanées.                  [D-12]
        $this->service()->subscribe($this->price('STARTER'));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Subscription::query()->create([
            'plan_price_id' => $this->price('BOUTIQUE')->id,
            'quantity' => 1,
            'status' => 'ACTIVE',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    /* ------------------------------------------------------ le prorata */

    #[Test]
    public function opening_a_shop_mid_period_is_prorated_to_the_centime(): void
    {
        $start = CarbonImmutable::parse('2026-03-01 00:00:00');
        CarbonImmutable::setTestNow($start);

        $this->makeLocation($this->org, 'PETIONVILLE');
        $this->makeLocation($this->org, 'CROIX');
        $subscription = $this->service()->subscribe($this->price('MULTI'));   // 3 sites, 60,00 $

        // Quinze jours sur trente-et-un : un quatrième site ouvre.
        $at = CarbonImmutable::parse('2026-03-17 00:00:00');
        CarbonImmutable::setTestNow($at);
        $this->makeLocation($this->org, 'TABARRE');
        $this->service()->syncQuantity($at);

        $this->assertSame(4, $subscription->fresh()->quantity);

        $adjustment = Invoice::query()->orderByDesc('created_at')->first();
        $remaining = (int) $at->diffInSeconds($subscription->current_period_end);
        $total = (int) $subscription->current_period_start->diffInSeconds($subscription->current_period_end);
        $expected = intdiv(2000 * $remaining * 2 + $total, $total * 2);

        $this->assertSame($expected, $adjustment->total_minor);
        $this->assertSame('OPEN', $adjustment->status);

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function closing_a_shop_bills_nothing_and_takes_effect_next_period(): void
    {
        $start = CarbonImmutable::parse('2026-03-01 00:00:00');
        CarbonImmutable::setTestNow($start);

        foreach (['PETIONVILLE', 'CROIX', 'TABARRE'] as $code) {
            $this->makeLocation($this->org, $code);
        }
        $this->service()->subscribe($this->price('MULTI'));       // 4 sites

        $billedBefore = Invoice::query()->count();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-17 00:00:00'));
        Location::query()->where('code', 'TABARRE')->update(['active' => false]);
        $subscription = $this->service()->syncQuantity();

        // La quantité tombe tout de suite — c'est la facture suivante qui est
        // plus petite. La base ne sait pas porter un avoir, et la règle
        // s'explique en une phrase à un commerçant.
        $this->assertSame(3, $subscription->quantity);
        $this->assertSame($billedBefore, Invoice::query()->count());

        // Et le renouvellement facture bien trois sites, pas quatre.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 00:00:00'));
        $renewed = $this->service()->renew();
        $this->assertSame(6000, $renewed->periodAmount()->minor);

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function opening_then_closing_the_same_day_bills_the_opening_only(): void
    {
        // L'asymétrie assumée : la hausse est facturée au prorata, la baisse
        // n'est pas remboursée. Un site ouvert puis fermé le même jour laisse
        // donc un ajustement, et un seul.
        $start = CarbonImmutable::parse('2026-03-01 00:00:00');
        CarbonImmutable::setTestNow($start);

        foreach (['PETIONVILLE', 'CROIX'] as $code) {
            $this->makeLocation($this->org, $code);
        }
        $this->service()->subscribe($this->price('MULTI'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-11 09:30:00'));
        $opened = $this->makeLocation($this->org, 'TABARRE');
        $this->service()->syncQuantity();

        $afterOpening = Invoice::query()->count();

        Location::query()->where('id', $opened->id)->update(['active' => false]);
        $subscription = $this->service()->syncQuantity();

        $this->assertSame(3, $subscription->quantity);
        $this->assertSame($afterOpening, Invoice::query()->count());

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function a_quantity_that_did_not_change_bills_nothing(): void
    {
        $this->service()->subscribe($this->price('BOUTIQUE'));
        $before = Invoice::query()->count();

        $this->service()->syncQuantity();

        $this->assertSame($before, Invoice::query()->count());
    }

    /* ------------------------------------------------------ les factures */

    #[Test]
    public function an_invoice_lines_sum_exactly_to_its_total(): void
    {
        $this->makeLocation($this->org, 'PETIONVILLE');
        $this->makeLocation($this->org, 'CROIX');
        $this->service()->subscribe($this->price('MULTI'));

        $invoice = Invoice::query()->with('lines')->sole();

        $this->assertSame(
            $invoice->subtotal_minor,
            (int) $invoice->lines->sum('total_minor'),
            'une facture dont les lignes ne s\'additionnent pas est contestable',
        );
        $this->assertSame($invoice->subtotal_minor + $invoice->tax_minor, $invoice->total_minor);
    }

    #[Test]
    public function invoice_numbers_are_sequential_per_organization(): void
    {
        $this->service()->subscribe($this->price('BOUTIQUE'));
        $this->service()->renew();
        $this->service()->renew();

        $numbers = Invoice::query()->orderBy('created_at')->pluck('number')->all();

        $this->assertCount(3, $numbers);
        $this->assertSame($numbers, array_values(array_unique($numbers)));
    }

    /* --------------------------------------------------- le cycle de vie */

    #[Test]
    public function renewing_opens_the_next_period_from_the_previous_end(): void
    {
        $subscription = $this->service()->subscribe($this->price('BOUTIQUE'));
        $firstEnd = $subscription->current_period_end;

        $renewed = $this->service()->renew();

        $this->assertTrue($renewed->current_period_start->equalTo($firstEnd));
        $this->assertSame(2, Invoice::query()->count());
    }

    #[Test]
    public function an_unpaid_subscription_keeps_selling_during_the_grace_period(): void
    {
        // On ne coûte pas sa journée à un commerçant pour notre problème de
        // recouvrement : la caisse continue pendant la grâce.
        $this->service()->subscribe($this->price('BOUTIQUE'));

        $pastDue = $this->service()->markPastDue();
        $this->assertSame('PAST_DUE', $pastDue->status);
        $this->assertTrue($pastDue->isLive());

        $stillGraced = $this->service()->suspendIfGraceElapsed(now()->addDays(3));
        $this->assertSame('PAST_DUE', $stillGraced->status);

        $suspended = $this->service()->suspendIfGraceElapsed(
            now()->addDays(SubscriptionService::GRACE_DAYS + 1),
        );
        $this->assertSame('SUSPENDED', $suspended->status);
    }

    #[Test]
    public function cancelling_keeps_the_period_already_paid(): void
    {
        $subscription = $this->service()->subscribe($this->price('BOUTIQUE'));

        $cancelled = $this->service()->cancel();

        $this->assertSame('ACTIVE', $cancelled->status, 'le mois réglé est gardé');
        $this->assertTrue($cancelled->cancel_at->equalTo($subscription->current_period_end));
    }

    #[Test]
    public function changing_plan_replaces_the_subscription(): void
    {
        $this->service()->subscribe($this->price('STARTER'));

        $changed = $this->service()->changePlan($this->price('BOUTIQUE'));

        $this->assertSame('BOUTIQUE', $changed->price->plan->code);
        $this->assertSame(1, Subscription::query()->live()->count());
        $this->assertSame(2, Subscription::query()->count());
    }

    /* ------------------------------------------------------------- l'API */

    #[Test]
    public function the_api_subscribes_and_reads_back(): void
    {
        $priceId = $this->price('BOUTIQUE')->id;
        $token = $this->asOwner();

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'SUB-1')
            ->postJson('/api/v1/subscription', ['plan_price_id' => $priceId])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.plan.code', 'BOUTIQUE');

        $this->withToken($token)->getJson('/api/v1/subscription')
            ->assertOk()->assertJsonPath('data.period_amount_minor', 2500);

        $this->withToken($token)->getJson('/api/v1/invoices')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function replaying_the_subscribe_call_does_not_create_a_second_one(): void
    {
        $priceId = $this->price('BOUTIQUE')->id;
        $token = $this->asOwner();

        $call = fn () => $this->withToken($token)
            ->withHeader('Idempotency-Key', 'SUB-1')
            ->postJson('/api/v1/subscription', ['plan_price_id' => $priceId]);

        $call()->assertCreated();
        $call()->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, Subscription::query()->count());
    }

    #[Test]
    public function a_cashier_may_see_the_subscription_but_not_the_invoices(): void
    {
        // Un caissier a le droit de savoir si la caisse est suspendue ; il n'a
        // pas à voir ce que le patron paie.
        $this->service()->subscribe($this->price('BOUTIQUE'));

        $cashier = $this->makeMember($this->org, 'CASHIER', [], 'kesye@nexa.test');
        $cashier->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        app(OrgContext::class)->forget();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'kesye@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/subscription')->assertOk();
        $this->withToken($token)->getJson('/api/v1/invoices')->assertForbidden();
        $this->withToken($token)->withHeader('Idempotency-Key', 'X')
            ->postJson('/api/v1/subscription/cancel')->assertForbidden();
    }

    #[Test]
    public function one_organization_cannot_read_another_invoice(): void
    {
        $this->service()->subscribe($this->price('BOUTIQUE'));
        $invoiceId = Invoice::query()->sole()->id;
        $token = $this->asOwner();

        // Une autre organisation, avec son propre propriétaire.
        app(OrgContext::class)->forget();
        $theirs = Organization::query()->create(['name' => 'Gwo Machann', 'slug' => 'gwo-machann']);
        $theirOwner = $this->makeMember($theirs, 'OWNER', [], 'lot@nexa.test');
        $theirOwner->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        app(OrgContext::class)->forget();

        $theirToken = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'lot@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        // Introuvable, pas interdite : un 403 confirmerait qu'elle existe.
        $this->withToken($theirToken)->getJson("/api/v1/invoices/{$invoiceId}")->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();
    }

    #[Test]
    public function a_withdrawn_price_can_no_longer_be_subscribed_to(): void
    {
        $price = $this->price('BOUTIQUE');
        DB::table('plan_prices')->where('id', $price->id)->update(['valid_to' => now()->subDay()]);

        $this->withToken($this->asOwner())
            ->withHeader('Idempotency-Key', 'SUB-OLD')
            ->postJson('/api/v1/subscription', ['plan_price_id' => $price->id])
            ->assertNotFound();
    }
}
