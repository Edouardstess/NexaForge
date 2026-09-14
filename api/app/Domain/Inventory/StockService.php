<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Decimal;
use App\Support\Tenancy\OrgContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Le stock : un solde courant rapide, une histoire immuable.           [D-08]
 *
 * Toute écriture verrouille d'abord la ligne de solde. C'est ce verrou qui
 * résout le cas des deux caissiers vendant le dernier article : la seconde
 * transaction attend, relit la valeur à jour, et décide en connaissance de
 * cause au lieu d'écraser la première.
 *
 * Le stock a le DROIT de devenir négatif : une vente déjà encaissée n'est
 * jamais refusée, et un solde négatif est l'information exacte que le compte
 * physique ne correspond plus au compte théorique.                     [D-09]
 */
final class StockService
{
    private const SCALE = 4;

    public function __construct(private readonly OrgContext $context) {}

    /**
     * Applique un mouvement et met à jour le solde, dans la MÊME transaction.
     * Doit être appelé à l'intérieur d'une transaction ouverte par l'appelant.
     *
     * @param  string  $quantity  signée : négative pour une sortie
     * @return string le nouveau solde
     */
    public function move(
        string $locationId,
        string $variantId,
        string $type,
        string $quantity,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?int $unitCostMinor = null,
        ?string $costCurrency = null,
        ?string $reason = null,
    ): string {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'StockService::move doit tourner dans une transaction : le mouvement et le solde '.
                'ne peuvent pas être écrits séparément sans risquer de diverger.'
            );
        }

        if (Decimal::toScaledInt($quantity, self::SCALE) === 0) {
            throw new LogicException('Un mouvement de stock ne peut pas être de quantité nulle.');
        }

        $organizationId = $this->context->organizationId();

        // Crée la ligne si elle n'existe pas, sans écraser une valeur
        // existante : ON CONFLICT DO NOTHING plutôt qu'un check-then-insert
        // qu'une requête concurrente pourrait traverser.
        DB::statement(
            'INSERT INTO stock_levels (organization_id, location_id, product_variant_id, quantity, reserved)
             VALUES (?, ?, ?, 0, 0)
             ON CONFLICT (location_id, product_variant_id) DO NOTHING',
            [$organizationId, $locationId, $variantId],
        );

        // LE verrou. Tout le reste en dépend.
        $current = DB::selectOne(
            'SELECT quantity FROM stock_levels
              WHERE location_id = ? AND product_variant_id = ?
              FOR UPDATE',
            [$locationId, $variantId],
        );

        $newQuantity = Decimal::fromScaledInt(
            Decimal::toScaledInt((string) $current->quantity, self::SCALE)
                + Decimal::toScaledInt($quantity, self::SCALE),
            self::SCALE,
        );

        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'location_id' => $locationId,
            'product_variant_id' => $variantId,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            'cost_currency' => $costCurrency,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'created_by' => $this->context->userId(),
            'created_at' => now(),
        ]);

        DB::table('stock_levels')
            ->where('location_id', $locationId)
            ->where('product_variant_id', $variantId)
            ->update(['quantity' => $newQuantity, 'updated_at' => now()]);

        // Un solde négatif n'est pas masqué : il remonte au gérant.
        if (Decimal::toScaledInt($newQuantity, self::SCALE) < 0) {
            $this->flagDiscrepancy($locationId, $variantId, $newQuantity);
        }

        return $newQuantity;
    }

    public function quantityAt(string $locationId, string $variantId): string
    {
        $row = DB::table('stock_levels')
            ->where('location_id', $locationId)
            ->where('product_variant_id', $variantId)
            ->value('quantity');

        return $row === null ? '0.0000' : (string) $row;
    }

    /**
     * Recompte l'histoire et la compare au solde. Tourne chaque nuit : un
     * solde qui dérive en silence est le scénario dont on ne se relève pas.
     *
     * @return list<array{location_id: string, product_variant_id: string, expected: string, actual: string}>
     */
    public function reconcile(): array
    {
        $drifted = DB::select(<<<'SQL'
            SELECT l.location_id,
                   l.product_variant_id,
                   l.quantity                        AS actual,
                   coalesce(sum(m.quantity), 0)      AS expected
              FROM stock_levels l
              LEFT JOIN stock_movements m
                     ON m.location_id = l.location_id
                    AND m.product_variant_id = l.product_variant_id
             WHERE l.organization_id = ?
             GROUP BY l.location_id, l.product_variant_id, l.quantity
            HAVING l.quantity <> coalesce(sum(m.quantity), 0)
        SQL, [$this->context->organizationId()]);

        foreach ($drifted as $row) {
            DB::table('stock_discrepancies')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->context->organizationId(),
                'location_id' => $row->location_id,
                'product_variant_id' => $row->product_variant_id,
                'expected_quantity' => $row->expected,
                'actual_quantity' => $row->actual,
                'origin' => 'RECONCILIATION',
                'created_at' => now(),
            ]);
        }

        return array_map(fn ($row): array => [
            'location_id' => $row->location_id,
            'product_variant_id' => $row->product_variant_id,
            'expected' => (string) $row->expected,
            'actual' => (string) $row->actual,
        ], $drifted);
    }

    private function flagDiscrepancy(string $locationId, string $variantId, string $quantity): void
    {
        DB::table('stock_discrepancies')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->context->organizationId(),
            'location_id' => $locationId,
            'product_variant_id' => $variantId,
            'expected_quantity' => '0',
            'actual_quantity' => $quantity,
            'origin' => 'OFFLINE_SYNC',
            'created_at' => now(),
        ]);
    }
}
