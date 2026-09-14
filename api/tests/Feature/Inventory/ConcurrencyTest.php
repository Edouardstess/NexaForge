<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\StockService;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\CommittedTestCase;

/**
 * Le cas que les documents posaient en §67 sans le résoudre : deux caissiers
 * vendent le dernier article au même instant.                          [D-08]
 */
final class ConcurrencyTest extends CommittedTestCase
{
    private Location $shop;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $this->shop = $this->makeLocation($org, 'DELMAS');
        $this->actingAsMember($this->makeMember($org, 'OWNER'));

        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $this->variantId = $item['variant']->id;
    }

    #[Test]
    public function two_concurrent_sales_of_the_last_item_do_not_lose_a_movement(): void
    {
        // On amène le stock à 1, puis deux connexions distinctes vendent en
        // même temps. Le verrou FOR UPDATE sérialise : la seconde attend,
        // relit 0, et écrit -1 — au lieu d'écraser la première avec 0.
        DB::transaction(fn () => app(StockService::class)->move(
            $this->shop->id, $this->variantId, 'ADJUSTMENT', '-99',
        ));

        $this->assertSame('1.0000', app(StockService::class)->quantityAt($this->shop->id, $this->variantId));

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            config('database.connections.pgsql.host'),
            config('database.connections.pgsql.port'),
            config('database.connections.pgsql.database'),
        );

        $a = new \PDO($dsn, config('database.connections.pgsql.username'), config('database.connections.pgsql.password'));
        $b = new \PDO($dsn, config('database.connections.pgsql.username'), config('database.connections.pgsql.password'));
        $a->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $b->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $lock = 'SELECT quantity FROM stock_levels WHERE location_id = ? AND product_variant_id = ? FOR UPDATE';

        $a->beginTransaction();
        $readA = $a->prepare($lock);
        $readA->execute([$this->shop->id, $this->variantId]);
        $quantityA = (float) $readA->fetchColumn();

        // B demande le même verrou : la requête doit BLOQUER tant que A tient
        // la ligne. On le vérifie avec un timeout court.
        $b->exec("SET lock_timeout = '300ms'");
        $b->beginTransaction();
        $blocked = false;

        try {
            $readB = $b->prepare($lock);
            $readB->execute([$this->shop->id, $this->variantId]);
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'lock timeout')
                || str_contains($e->getMessage(), '55P03');
        }

        $this->assertTrue($blocked, 'La seconde transaction aurait dû être bloquée par le verrou.');

        $b->rollBack();

        // A termine sa vente.
        $a->prepare('UPDATE stock_levels SET quantity = ? WHERE location_id = ? AND product_variant_id = ?')
            ->execute([$quantityA - 1, $this->shop->id, $this->variantId]);
        $a->commit();

        // B reprend et lit la valeur À JOUR, pas celle d'avant.
        $b->exec("SET lock_timeout = '5s'");
        $b->beginTransaction();
        $readB = $b->prepare($lock);
        $readB->execute([$this->shop->id, $this->variantId]);
        $quantityB = (float) $readB->fetchColumn();

        $this->assertSame(0.0, $quantityB, 'La seconde transaction doit voir le stock déjà décrémenté.');

        $b->rollBack();
        $a = $b = null;
    }

    #[Test]
    public function a_movement_outside_a_transaction_is_refused(): void
    {
        // Mouvement et solde ne peuvent pas être écrits séparément sans
        // risquer de diverger : le service refuse plutôt que de le tenter.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/dans une transaction/');

        app(StockService::class)->move($this->shop->id, $this->variantId, 'SALE', '-1');
    }

    #[Test]
    public function reconciliation_detects_a_balance_that_drifted_from_its_history(): void
    {
        // On simule une dérive en écrivant le solde sans mouvement.
        DB::table('stock_levels')
            ->where('location_id', $this->shop->id)
            ->where('product_variant_id', $this->variantId)
            ->update(['quantity' => '93.0000']);

        $drift = app(StockService::class)->reconcile();

        $this->assertCount(1, $drift);
        $this->assertSame('93.0000', $drift[0]['actual']);
        $this->assertSame('100.0000', $drift[0]['expected']);

        $this->assertDatabaseHas('stock_discrepancies', [
            'product_variant_id' => $this->variantId,
            'origin' => 'RECONCILIATION',
        ]);
    }

    #[Test]
    public function stock_movements_cannot_be_rewritten(): void
    {
        DB::table('stock_movements')->update(['quantity' => '999']);
        DB::table('stock_movements')->delete();

        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->assertSame('100.0000', DB::table('stock_movements')->value('quantity'));
    }
}
