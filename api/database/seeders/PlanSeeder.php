<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les trois formules du plan d'affaires.
 *
 * Les montants sont en USD parce que c'est la monnaie dans laquelle le
 * business raisonne ; la facture part en gourdes au taux du mois. C'est
 * exactement ce que `plan_prices` permet, et pourquoi le prix ne vit pas sur
 * le plan lui-même.                                                    [D-12]
 *
 * Converge à chaque exécution : sûr en production.
 */
final class PlanSeeder extends Seeder
{
    /** [code, nom, montant USD en cents, par site, sites minimum, limites] */
    private const PLANS = [
        ['STARTER',  'Starter',  1500, false, 1, ['max_locations' => 1, 'max_registers' => 1]],
        ['BOUTIQUE', 'Boutique', 2500, false, 1, ['max_locations' => 1, 'max_registers' => 3]],
        ['MULTI',    'Multi',    2000, true,  3, ['max_locations' => null, 'max_registers' => null]],
    ];

    public function run(): void
    {
        foreach (self::PLANS as [$code, $name, $amount, $perLocation, $minLocations, $features]) {
            DB::table('plans')->upsert(
                [[
                    'id' => (string) Str::uuid7(),
                    'code' => $code,
                    'name' => $name,
                    'features' => json_encode($features, JSON_THROW_ON_ERROR),
                    'active' => true,
                ]],
                ['code'],
                ['name', 'features', 'active'],
            );

            $planId = DB::table('plans')->where('code', $code)->value('id');

            // Une grille tarifaire s'ajoute, elle ne s'écrase pas : une
            // facture émise l'an dernier doit continuer à pointer le prix
            // qu'elle a réellement appliqué.
            $exists = DB::table('plan_prices')
                ->where('plan_id', $planId)
                ->where('currency', 'USD')
                ->where('interval', 'MONTH')
                ->whereNull('valid_to')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('plan_prices')->insert([
                'id' => (string) Str::uuid7(),
                'plan_id' => $planId,
                'currency' => 'USD',
                'interval' => 'MONTH',
                'amount_minor' => $amount,
                'per_location' => $perLocation,
                'min_locations' => $minLocations,
                'valid_from' => now(),
                'valid_to' => null,
            ]);
        }

        $this->command?->info('Plan yo: Starter 15 $, Boutique 25 $, Multi 20 $/sit (min 3).');
    }
}
