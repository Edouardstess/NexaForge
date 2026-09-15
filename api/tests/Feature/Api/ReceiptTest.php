<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Money\ExchangeRateService;
use App\Domain\Sales\CheckoutService;
use App\Models\CashierSession;
use App\Models\Location;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReceiptTest extends TestCase
{
    private Location $shop;

    private CashierSession $session;

    private string $kola;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $org->forceFill(['name' => 'Ti Machann Delmas 33'])->save();
        $this->shop = $this->makeLocation($org, 'DELMAS');
        $this->shop->forceFill([
            'name' => 'Delmas 33',
            'address' => 'Ri Delmas 33, #12',
            'phone' => '+509 3700 0000',
        ])->save();

        $membership = $this->makeMember($org, 'OWNER', [], 'pwopriyete@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        $this->actingAsMember($membership);

        $this->kola = $this->makeCatalogItem('Kola Kouwòn', 5000, 'HTG', 1000, 3000, $this->shop)['variant']->id;
        $this->session = $this->openSession($this->shop);
    }

    private function sell(array $tenders): \App\Models\Order
    {
        return app(CheckoutService::class)->checkout(
            $this->session,
            [['variant_id' => $this->kola, 'quantity' => '3']],
            $tenders,
        );
    }

    private function asOwner(): string
    {
        if (! isset($this->token)) {
            app(\App\Support\Tenancy\OrgContext::class)->forget();
            $this->token = $this->postJson('/api/v1/auth/login', [
                'identifier' => 'pwopriyete@nexa.test', 'password' => 'modpas-123',
            ])->json('data.token');
        }

        return $this->token;
    }

    #[Test]
    public function the_receipt_holds_everything_a_customer_needs(): void
    {
        $order = $this->sell([['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 20000]]);

        $receipt = $this->withToken($this->asOwner())
            ->get("/api/v1/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=utf-8')
            ->getContent();

        $this->assertStringContainsString('TI MACHANN', $receipt);
        $this->assertStringContainsString('Ri Delmas 33', $receipt);
        $this->assertStringContainsString('DELMAS-000001', $receipt);
        $this->assertStringContainsString('Kola Kouwòn', $receipt);
        $this->assertStringContainsString('3 x 50,00', $receipt);
        $this->assertStringContainsString('TOTAL HTG', $receipt);
        $this->assertStringContainsString('150,00', $receipt);
        $this->assertStringContainsString('Monnen', $receipt);
        $this->assertStringContainsString('50,00', $receipt);   // 200 − 150
        $this->assertStringContainsString('Mesi anpil', $receipt);
    }

    #[Test]
    public function no_line_overflows_the_paper_width(): void
    {
        // Une ligne trop longue se replie n'importe où sur une thermique et
        // rend le ticket illisible.
        $order = $this->sell([['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 15000]]);

        foreach ([32, 42] as $width) {
            $receipt = $this->withToken($this->asOwner())
                ->get("/api/v1/orders/{$order->id}/receipt?width={$width}")
                ->assertOk()->getContent();

            foreach (explode("\n", $receipt) as $line) {
                $this->assertLessThanOrEqual(
                    $width,
                    mb_strlen($line),
                    "Ligne trop longue pour {$width} colonnes : « {$line} »",
                );
            }
        }
    }

    #[Test]
    public function a_foreign_currency_payment_prints_the_rate_that_was_applied(): void
    {
        // La preuve de ce qui a été appliqué le jour de la vente, si le
        // client revient une semaine plus tard.                        [D-04]
        app(ExchangeRateService::class)->record('USD', '132.50', now()->subDay());

        $order = $this->sell([['method' => 'CASH', 'currency' => 'USD', 'amount_minor' => 200]]);

        $receipt = $this->withToken($this->asOwner())
            ->get("/api/v1/orders/{$order->id}/receipt")->assertOk()->getContent();

        $this->assertStringContainsString('CASH USD', $receipt);
        $this->assertStringContainsString('2,00', $receipt);
        $this->assertStringContainsString('@ 132.5', $receipt);
        $this->assertStringContainsString('265,00', $receipt);
    }

    #[Test]
    public function an_offline_sale_says_so_on_the_ticket(): void
    {
        $order = app(CheckoutService::class)->checkout(
            $this->session,
            [['variant_id' => $this->kola, 'quantity' => '1', 'unit_price_minor' => 5000]],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
            takenAt: now()->subHours(3),
            origin: 'OFFLINE',
        );

        $receipt = $this->withToken($this->asOwner())
            ->get("/api/v1/orders/{$order->id}/receipt")->assertOk()->getContent();

        // Sinon le client ne comprend pas pourquoi l'heure du ticket ne colle
        // pas avec l'heure d'enregistrement.
        $this->assertStringContainsString('san koneksyon', $receipt);
    }

    #[Test]
    public function the_escpos_stream_starts_with_init_and_ends_with_a_cut(): void
    {
        $order = $this->sell([['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 15000]]);

        $bytes = $this->withToken($this->asOwner())
            ->get("/api/v1/orders/{$order->id}/receipt?format=escpos")
            ->assertOk()
            ->assertHeader('content-type', 'application/octet-stream')
            ->getContent();

        $this->assertStringStartsWith("\x1B@", $bytes, 'initialisation ESC @');
        $this->assertStringEndsWith("\x1DV\x42\x00", $bytes, 'coupe du papier GS V');
        $this->assertStringContainsString('DELMAS-000001', $bytes);
    }

    #[Test]
    public function accents_are_transliterated_for_a_cp437_printer(): void
    {
        // Une thermique bon marché sort du CP437 : « ò » en UTF-8 y devient
        // deux caractères illisibles sur le ticket d'un client.
        $order = $this->sell([['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 15000]]);

        $bytes = $this->withToken($this->asOwner())
            ->get("/api/v1/orders/{$order->id}/receipt?format=escpos")->assertOk()->getContent();

        $body = substr($bytes, strpos($bytes, "\x1Ba\x00") + 3);

        $this->assertStringNotContainsString('ò', $body);
        $this->assertStringNotContainsString('è', $body);
        $this->assertStringContainsString('Kola Kouwon', $body);
    }

    #[Test]
    public function a_receipt_from_another_organization_is_not_found(): void
    {
        $order = $this->sell([['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 15000]]);

        // Déplacer la vente hors du contexte : la garde d'isolation refuse
        // toute écriture vers une autre organisation, ce qu'on veut par
        // ailleurs — on contourne donc par une écriture brute.
        $theirs = \App\Models\Organization::query()->create([
            'name' => 'Gwo Machann', 'slug' => 'gwo-machann',
        ]);
        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $order->id)
            ->update(['organization_id' => $theirs->id]);

        $this->withToken($this->asOwner())
            ->getJson("/api/v1/orders/{$order->id}/receipt")
            ->assertNotFound();
    }
}
