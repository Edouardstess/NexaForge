# Nexa POS

Logiciel de caisse et de stock pour les commerces de détail haïtiens.
Fonctionne **sans internet et sans courant**, en **gourdes et en dollars**.

Édité par Nexa Forge.

---

## Périmètre

**Dans le périmètre** — identité et organisations, RBAC par site, catalogue avec
variantes et conditionnements, prix versionnés multi-devises, TCA, stock,
session de caisse, commandes, paiements multi-devises (espèces, MonCash,
NatCash), remboursements, ledger en partie double, abonnement, fonctionnement
hors-ligne, audit.

**Hors périmètre** — livraison, flotte, services, agro, annuaire grand public,
marketplace, restauration. Voir `docs/future/`. Ces produits reviendront après
la porte de sortie de la semaine 24, pas avant.

## Documents

| Fichier | Contenu |
|---|---|
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Les 13 décisions verrouillées. À lire en premier. |
| [`docs/schema-mvp.sql`](docs/schema-mvp.sql) | Le schéma PostgreSQL 16 complet du MVP. |
| [`docs/schema-invariants.test.sql`](docs/schema-invariants.test.sql) | Les 12 invariants que la base doit garantir. |
| [`docs/PLAN.md`](docs/PLAN.md) | Économie, calendrier 16 semaines, portes de sortie. |

**Ce dépôt est l'unique source de vérité.** Tout document hors de git est
obsolète par construction.

## Vérifier le schéma

```bash
createdb nexa_mvp
psql -d nexa_mvp -v ON_ERROR_STOP=1 -f docs/schema-mvp.sql
psql -d nexa_mvp -f docs/schema-invariants.test.sql
```

Les `ERROR` affichés par le second fichier sont **attendus** : chaque test
vérifie qu'un état impossible est bien refusé par la base.

| # | Invariant vérifié |
|---|---|
| 1 | Une transaction ledger déséquilibrée est refusée au `COMMIT` |
| 2 | Une transaction équilibrée passe, quel que soit l'ordre d'insertion |
| 3 | La devise d'une écriture est nécessairement celle de son compte |
| 4 | Les écritures de ledger sont immuables |
| 5 | L'équilibre est vérifié **par devise** |
| 6 | Deux prix qui se chevauchent sont impossibles |
| 7 | Un total de commande incohérent est refusé |
| 8 | Un rejeu hors-ligne ne crée pas de doublon |
| 9 | Deux sessions ouvertes sur la même caisse sont impossibles |
| 10 | Un stock négatif est **accepté** — une vente encaissée n'est jamais rejetée |
| 11 | Les mouvements de stock sont immuables |
| 12 | Aucune colonne monétaire en virgule flottante |

État au dernier passage : les 12 se comportent comme spécifié sur PostgreSQL 16.13.

## Stack

Laravel 12 / PHP 8.3 · PostgreSQL 16 · Redis · React + TypeScript + Vite ·
SQLite côté caisse · Expo (plus tard).

Le choix de la stack et sa justification : `docs/DECISIONS.md`, décision D-01.
