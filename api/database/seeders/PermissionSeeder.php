<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Données de référence : permissions et modèles de rôles.
 *
 * Converge à chaque exécution — sûr en production. Les policies vérifient
 * TOUJOURS une permission, jamais un nom de rôle.                      [D-03]
 */
final class PermissionSeeder extends Seeder
{
    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'catalogue' => [
            'product.read' => 'Consulter les produits',
            'product.create' => 'Créer un produit',
            'product.update' => 'Modifier un produit',
            'product.delete' => 'Supprimer un produit',
            'price.read' => 'Consulter les prix',
            'price.update' => 'Modifier un prix',
        ],
        'stock' => [
            'inventory.read' => 'Consulter le stock',
            'inventory.adjust' => 'Ajuster le stock',
            'inventory.transfer' => 'Transférer du stock',
            'inventory.receive' => 'Réceptionner une commande fournisseur',
            'supplier.read' => 'Consulter les fournisseurs',
            'supplier.manage' => 'Gérer les fournisseurs',
        ],
        'caisse' => [
            'session.open' => 'Ouvrir une session de caisse',
            'session.close' => 'Clôturer une session de caisse',
            'session.read' => 'Consulter les sessions de caisse',
            'order.read' => 'Consulter les ventes',
            'order.create' => 'Encaisser une vente',
            'order.void' => 'Annuler une vente',
            'order.refund' => 'Rembourser une vente',
            'order.discount' => 'Appliquer une remise',
        ],
        'clients' => [
            'customer.read' => 'Consulter les clients',
            'customer.manage' => 'Gérer les clients',
        ],
        'finance' => [
            'ledger.read' => 'Consulter le grand livre',
            'exchange_rate.update' => 'Mettre à jour le taux de change',
            'report.read' => 'Consulter les rapports',
            'report.export' => 'Exporter les rapports',
        ],
        'administration' => [
            'location.read' => 'Consulter les points de vente',
            'location.manage' => 'Gérer les points de vente',
            'user.read' => 'Consulter les utilisateurs',
            'user.manage' => 'Gérer les utilisateurs',
            'role.manage' => 'Gérer les rôles',
            'audit.read' => 'Consulter le journal d\'audit',
            'conflict.read' => 'Consulter les conflits de synchronisation',
            'conflict.resolve' => 'Résoudre un conflit de synchronisation',
            'billing.manage' => 'Gérer l\'abonnement',
        ],
    ];

    /**
     * Modèles de rôles. Une entrée « domaine.* » accorde toutes les permissions
     * du GROUPE nommé — pas un préfixe de code, qui laisserait passer des trous
     * silencieux entre le nom du groupe et celui des permissions.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_TEMPLATES = [
        'OWNER' => ['*'],
        'MANAGER' => [
            'group:catalogue', 'group:stock', 'group:caisse', 'group:clients',
            'report.read', 'report.export', 'session.read',
            'conflict.read', 'conflict.resolve', 'location.read', 'user.read',
        ],
        'CASHIER' => [
            'product.read', 'price.read', 'inventory.read',
            'session.open', 'session.close', 'session.read',
            'order.read', 'order.create',
            'customer.read', 'customer.manage',
        ],
        'STOCK_KEEPER' => [
            'product.read', 'price.read',
            'group:stock',
            'report.read',
        ],
        'ACCOUNTANT' => [
            'order.read', 'session.read', 'ledger.read',
            'report.read', 'report.export', 'audit.read',
            'product.read', 'price.read', 'inventory.read',
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $group => $permissions) {
            foreach ($permissions as $code => $description) {
                DB::table('permissions')->upsert(
                    [['code' => $code, 'group' => $group, 'description' => $description]],
                    ['code'],
                    ['group', 'description'],
                );
            }
        }

        foreach (self::ROLE_TEMPLATES as $code => $rules) {
            DB::table('roles')->upsert(
                [['id' => (string) \Illuminate\Support\Str::uuid7(), 'organization_id' => null,
                  'code' => $code, 'name' => $code, 'created_at' => $now]],
                ['organization_id', 'code'],
                ['name'],
            );

            $roleId = DB::table('roles')
                ->whereNull('organization_id')
                ->where('code', $code)
                ->value('id');

            $codes = $this->resolvePermissions($rules);

            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('role_permissions')->insert(array_map(
                fn (string $permission): array => ['role_id' => $roleId, 'permission_code' => $permission],
                $codes,
            ));
        }
    }

    /** @param list<string> $rules @return list<string> */
    private function resolvePermissions(array $rules): array
    {
        $all = array_merge(...array_map(array_keys(...), array_values(self::PERMISSIONS)));

        if ($rules === ['*']) {
            return $all;
        }

        $resolved = [];

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'group:')) {
                $group = substr($rule, 6);

                if (! isset(self::PERMISSIONS[$group])) {
                    throw new \LogicException("Groupe de permissions inconnu : {$group}");
                }

                $resolved = [...$resolved, ...array_keys(self::PERMISSIONS[$group])];

                continue;
            }

            if (! in_array($rule, $all, true)) {
                throw new \LogicException("Permission inconnue dans un modèle de rôle : {$rule}");
            }

            $resolved[] = $rule;
        }

        return array_values(array_unique($resolved));
    }
}
