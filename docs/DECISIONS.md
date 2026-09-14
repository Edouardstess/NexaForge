# Décisions verrouillées

Chaque décision ci-dessous résout un trou ou une contradiction identifiés dans
`Document Directeur v2.0` et `Master Architecture Document v1.0`.

**Une décision marquée VERROUILLÉE ne se rediscute pas sans un nouveau document
qui l'annule explicitement.** Le coût d'un projet solo n'est pas le code : c'est
la rediscussion permanente des mêmes choix.

| # | Décision | Problème résolu |
|---|---|---|
| D-01 | Stack : Laravel 12 + PostgreSQL 16 | Réécriture implicite de ce qui existe déjà |
| D-02 | Un seul produit : Nexa POS | Scope explosion (8 produits) |
| D-03 | Organization / Location, le mot « Tenant » est supprimé | 3 concepts, 2 définitions contradictoires |
| D-04 | Devise : HTG base, USD accepté, taux gelé à la vente | Trou total sur la double circulation |
| D-05 | Argent en BIGINT unités mineures ; quantités en NUMERIC(18,4) | `float`/`Decimal` mal séparés |
| D-06 | Prix : table `prices` versionnée, sans chevauchement | `Product` sans prix |
| D-07 | Taxe TCA 10 %, prix affichés TTC, décomposés à la ligne | `tax` sans modèle de taxe |
| D-08 | Stock : `stock_levels` verrouillé + `stock_movements` immuables | Stock courant non tranché |
| D-09 | Offline : une vente encaissée n'est **jamais** rejetée | Conflit posé puis abandonné |
| D-10 | Ledger : montants signés, équilibre par trigger différé | Invariant sans mécanisme |
| D-11 | Idempotence : table dédiée, pas de colonne nullable | Unicité fragile sur NULL |
| D-12 | Facturation sur l'Organization, quantité = nombre de Locations | Doc 1 §5 ≠ Doc 2 §23 |
| D-13 | Une seule marque, une seule couleur | POS sans couleur, Directory dupliquée |

---

## D-01 — Stack : Laravel 12 + PostgreSQL 16 · VERROUILLÉE

**Les documents prescrivaient** Node.js / Express / Prisma.

**Décision.** L'API est construite en Laravel 12 / PHP 8.3 sur PostgreSQL 16.
Le POS web est en React + TypeScript + Vite. Le mobile viendra en Expo.

**Pourquoi.** `edouardstess/sass_school` contient déjà, testé et en état de
marche : isolation multi-tenant par scope global, RBAC par permissions avec
cache versionné, `Money` en entiers mineurs, `audit_logs` append-only par règles
`DO INSTEAD NOTHING`, webhooks de paiement idempotents sur `(provider, event_id)`,
adaptateurs **MonCash et NatCash**, notifications multi-canal, abonnements SaaS.
C'est la « Phase 0 » des documents, déjà écrite.

Le critère décisif n'est pas l'élégance du langage : **c'est la seule stack sur
laquelle vous avez démontré que vous savez livrer.** 163 tests verts, PHPStan
niveau 5, en Laravel. Zéro ligne livrée en Node.

**Ce qu'on perd.** Le typage partagé bout-en-bout. Compensé par un contrat
OpenAPI généré depuis les FormRequests, d'où un client TypeScript généré. Coût :
une semaine, une fois.

**Ce qu'on ne réutilise PAS.** On ne forke pas SchoolFlow. On extrait ses
patterns et ses classes utilitaires dans un nouveau dépôt propre. Forker un
logiciel scolaire pour faire une caisse produirait une dette immédiate.

**Alternative rejetée.** Node/Prisma : 8 à 12 semaines pour retrouver l'état
actuel de la couche plateforme, sans bénéfice métier.

---

## D-02 — Un seul produit : Nexa POS · VERROUILLÉE

**Périmètre actif, en une phrase :**

> Un logiciel de caisse et de stock qui fonctionne sans internet et sans
> courant, pour les commerces de détail de Port-au-Prince, en gourdes et en
> dollars.

**Sortis du périmètre et des documents** (déplacés dans `docs/future/`, pas dans
une roadmap active) : NexaExpress, NexaFleet, NexaService, NexaAgro,
NexaDirectory, NexaPay, NexaResto.

NexaResto est le premier candidat au retour, **après** la porte de sortie de la
semaine 24 — pas avant.

L'architecture modulaire reste en place : c'est ce qui rend leur ajout possible
plus tard. Les documenter aujourd'hui ne les rend pas plus possibles ; ça
consomme seulement l'attention qui manque au produit qui doit être vendu.

---

## D-03 — Organization / Location · VERROUILLÉE

**Le mot « Tenant » est supprimé du vocabulaire du projet.** Il désignait tantôt
la frontière d'isolation, tantôt l'établissement physique, tantôt l'entité
facturée. Trois choses différentes.

| Concept | Rôle | Porté par |
|---|---|---|
| `Organization` | Le client. Frontière d'isolation **et** entité facturée. | `organization_id` sur **toute** ligne métier |
| `Location` | Un point de vente ou un dépôt physique. | `location_id` sur les lignes opérationnelles |
| `Membership` | Le lien user ↔ organization, porteur du rôle. | — |
| `MembershipLocation` | Les locations auxquelles ce membership donne accès. | — |

**Règle de portée.** `membership_locations` vide = accès à toutes les locations
de l'organisation. Non vide = accès restreint à cet ensemble.

C'est ce qui bouche le trou le plus grave des documents : sans cette table, un
gérant de la boutique A voyait la boutique B, parce que le `Membership` était
défini au niveau de l'organisation et le `TenantContext` au niveau du tenant.

**Contexte de requête :**

```php
OrgContext {
  userId, organizationId, membershipId,
  locationIds: uuid[],     // [] signifie « toutes »
  permissions: string[],
  baseCurrency: 'HTG',
}
```

L'`organization_id` et les `location_ids` **ne sont jamais lus depuis le corps de
la requête.** Un identifiant appartenant à une autre organisation renvoie `404`,
jamais `403` — un `403` serait un oracle d'existence.

---

## D-04 — Devise : HTG base, USD accepté, taux gelé · VERROUILLÉE

C'est le trou le plus grave des documents : `currency @default("USD")` partout,
MonCash/NatCash intégrés, et pas un mot sur la double circulation.

### Les trois règles

**1. Chaque organisation a une devise de comptabilisation** (`base_currency`,
`HTG` par défaut). Toute commande est comptabilisée dans cette devise. Les
rapports, les marges et le ledger sont dans cette devise.

**2. Un paiement porte sa propre devise et le taux utilisé, gelé.**

```
payments(currency, amount_minor, fx_rate, base_amount_minor)
```

`fx_rate` est copié depuis `exchange_rates` au moment de l'encaissement et
**stocké sur la ligne**. Une réimpression, un remboursement ou un rapport trois
semaines plus tard rejoue le taux d'origine, pas le taux du jour. Sans ça, vos
rapports changent rétroactivement à chaque variation du taux.

**3. Une commande accepte plusieurs paiements de devises différentes.**
1 000 HTG en espèces + 5 USD en espèces + le reste en MonCash sur la même vente
est le cas normal, pas l'exception. Le solde dû se calcule en devise de base ;
chaque encaissement y est converti à son propre taux.

### Le rendu de monnaie

Le rendu est **toujours rendu en devise de base** (HTG) sauf si le tiroir a la
devise et que le caissier choisit explicitement. Le taux de rendu est le même
que le taux d'encaissement de la ligne — jamais un taux différent, qui créerait
un écart de caisse structurel.

### La table des taux

```
exchange_rates(organization_id, base_currency, quote_currency,
               rate, effective_from, source, created_by)
```

`rate` = **nombre d'unités de `base_currency` pour 1 unité de `quote_currency`**
(base HTG, quote USD, rate 132.50). Saisi à la main par le propriétaire, daté.
Un job quotidien rappelle de le mettre à jour s'il a plus de 7 jours.

Pas d'API de taux automatique au MVP : en Haïti le taux pratiqué au comptoir
n'est pas le taux BRH, et un commerçant veut son taux, pas celui d'une banque.

### L'écart de change

Quand un paiement en USD est converti, la différence éventuelle entre la valeur
comptabilisée et la valeur de règlement va sur un compte de ledger dédié
`FX_GAIN_LOSS`. Elle n'est jamais absorbée dans le chiffre d'affaires.

### Le « dollar haïtien »

Beaucoup de commerces cotent en « dollars haïtiens » : une unité de compte
valant 5 gourdes, qui n'est **pas** une devise. La traiter comme une devise
corromprait le ledger.

**Décision :** `locations.display_unit ∈ {HTG, HTD5, USD}`. C'est un réglage
**d'affichage uniquement**. `HTD5` divise par 5 à l'écran et multiplie par 5 à
la saisie. Le stockage reste en centimes de gourde, toujours.

---

## D-05 — Types monétaires et quantités · VERROUILLÉE

| Nature | Type SQL | Raison |
|---|---|---|
| Argent | `BIGINT` en unités mineures | Pas d'arrondi, arithmétique exacte, classe `Money` déjà écrite |
| Taux de change | `NUMERIC(18,8)` | Précision nécessaire, jamais utilisé en accumulation |
| Quantité | `NUMERIC(18,4)` | Vendre 0,25 kg, 1,5 L |
| Taux de taxe | `INT` en points de base | 10 % = `1000`. Jamais de flottant. |
| Horodatage | `TIMESTAMPTZ`, stocké en UTC | Affiché en `America/Port-au-Prince` |

**Jamais de `FLOAT`, `REAL` ou `DOUBLE PRECISION` sur une valeur monétaire.**
Cette règle est vérifiée par un test qui interroge `information_schema`.

Les documents disaient `Decimal(18,2)`. `BIGINT` mineur est meilleur : c'est un
entier, il s'additionne sans surprise dans tous les langages, il traverse JSON
sans perte, et vous avez déjà la classe qui l'encapsule.

---

## D-06 — Les prix · VERROUILLÉE

Le modèle `Product` des documents n'avait **aucun prix**. On ne pouvait pas
vendre.

```
prices(product_variant_id, location_id NULL, currency,
       amount_minor, valid_from, valid_to)
```

**Résolution d'un prix** : prix spécifique à la location > prix par défaut de
l'organisation. Dans les deux cas, la ligne dont `valid_from <= now()` et
(`valid_to` est NULL ou `> now()`).

**Pas de chevauchement possible** : contrainte `EXCLUDE USING gist` sur
`(variant, location, currency, tstzrange(valid_from, valid_to))`. Deux prix
valides en même temps pour le même article est un état impossible au niveau de
la base, pas une règle applicative qu'on oublie.

**Historique conservé.** Changer un prix ferme la ligne courante et en ouvre une
nouvelle. On ne fait jamais d'`UPDATE` sur le montant — sinon la marge d'une
vente d'il y a trois mois change quand vous changez votre prix aujourd'hui.

**Le prix est figé sur la ligne de commande** (`order_items.unit_price_minor`),
avec le libellé de l'article. Une vente est un fait historique, pas une jointure.

### Variantes et unités

Le problème réel du commerce haïtien : on achète une caisse de 24, on vend à
l'unité.

```
product_variants(sku, barcode, unit_id, pack_size, base_variant_id)
```

`base_variant_id` pointe la variante unitaire, `pack_size` dit combien elle en
contient. La caisse de 24 a `pack_size = 24` et pointe la bouteille. Le stock
est tenu **uniquement sur la variante de base** ; une réception de 5 caisses
écrit un mouvement de `+120` bouteilles.

---

## D-07 — Taxes · VERROUILLÉE

```
tax_rates(organization_id, code, name, rate_bp, inclusive, active)
```

**Valeur par défaut Haïti : TCA, `rate_bp = 1000` (10 %), `inclusive = true`.**

En commerce de détail haïtien le prix affiché est le prix payé. Les prix sont
donc saisis **TTC** et décomposés à la ligne :

```
base = round(ttc * 10000 / (10000 + rate_bp))
taxe = ttc - base
```

L'arrondi se fait sur la taxe, jamais sur la base, pour que la somme des lignes
égale exactement le total encaissé.

Sur la commande : `subtotal_minor` est hors taxe, `tax_minor` est la taxe,
`total_minor = subtotal - discount + tax`. Cette égalité est une contrainte
`CHECK` en base.

Beaucoup de petits commerces ne sont pas assujettis : `rate_bp = 0` est un
réglage valide, et le ticket n'affiche alors aucune ligne de taxe.

---

## D-08 — Le stock courant · VERROUILLÉE

Les documents ne tranchaient pas entre « somme des mouvements » et « table de
soldes ». C'est la décision qui détermine si la caisse répond en 40 ms ou en 3 s
à la deuxième année.

**Décision : les deux, avec un rôle clair pour chacun.**

```
stock_levels(location_id, product_variant_id, quantity, reserved, updated_at)
   PRIMARY KEY (location_id, product_variant_id)          -- l'état courant, rapide

stock_movements(... type, quantity signée, reference, created_by, created_at)
   RULE ... DO INSTEAD NOTHING sur UPDATE et DELETE       -- l'histoire, immuable
```

**Règle d'écriture.** Tout mouvement s'écrit dans la même transaction que la
mise à jour du solde, et le solde est verrouillé d'abord :

```sql
SELECT quantity FROM stock_levels
 WHERE location_id = $1 AND product_variant_id = $2
   FOR UPDATE;
```

Ce verrou est exactement ce qui résout le cas « deux caissiers vendent le
dernier article » de la §67 des documents : la deuxième transaction attend, lit
la valeur à jour, et décide en connaissance de cause.

**Réconciliation.** Un job nocturne recompte `SUM(stock_movements.quantity)` par
`(location, variant)` et le compare à `stock_levels`. Tout écart crée une ligne
`stock_discrepancies` et alerte. Un solde qui dérive silencieusement est le
scénario dont on ne se relève pas.

---

## D-09 — Les conflits hors-ligne · VERROUILLÉE

Les documents posaient le cas puis répondaient « émettre un événement de
conflit ». Voici la règle complète.

### Règle fondatrice

> **Une vente déjà encaissée n'est jamais rejetée par le serveur.**

L'argent est dans le tiroir et la marchandise est partie. Le serveur n'a pas
autorité sur un fait qui s'est produit. Son travail est d'enregistrer et de
signaler, pas d'annuler.

Le serveur reste autoritaire sur **les référentiels** — prix, catalogue, droits
— jamais sur **les faits de caisse**.

### Les six cas, et leur résolution

| Cas | Décision serveur | Trace |
|---|---|---|
| Stock insuffisant | **Accepter.** Le stock passe en négatif. | `sync_conflicts: STOCK_NEGATIVE`, alerte gérant |
| Prix modifié depuis | **Le prix de la caisse gagne.** C'est ce que le client a payé. | `PRICE_VARIANCE` si l'écart dépasse le seuil |
| Article désactivé | **Accepter.** L'article est rouvert en `archived`. | `ARCHIVED_PRODUCT` |
| Remise expirée | **Honorer.** | `EXPIRED_DISCOUNT` |
| Même clé d'idempotence | **Rejouer la réponse d'origine**, statut `200`, pas `409`. | aucune |
| Session de caisse déjà clôturée | **Rattacher quand même.** Le Z est recalculé et passe en `AMENDED`. | `SESSION_CLOSED`, audit |

Un stock négatif n'est pas un bug : c'est l'information exacte que le compte
physique ne correspond plus au compte théorique. Le masquer serait le bug.

### Mécanique

Chaque mutation locale porte : `client_order_id`, `idempotency_key`, `taken_at`
(horloge de la caisse), `origin = OFFLINE`. États locaux : `PENDING → SYNCING →
SYNCED | FAILED | CONFLICT`.

`taken_at` est distinct de `created_at`. Les rapports journaliers se font sur
`taken_at` — sinon une caisse restée hors-ligne six heures fait apparaître
toutes ses ventes du matin dans le chiffre du soir.

---

## D-10 — Le ledger · VERROUILLÉE

L'invariant `SUM(DEBIT) = SUM(CREDIT)` était énoncé sans aucun mécanisme. Un
ledger dont l'équilibre dépend du fait que le code ne se trompe jamais est un
journal, pas un ledger.

**1. `organization_id` et `currency` sur `ledger_entries`.** Ils manquaient.
Seul le compte les portait — une erreur de jointure aurait mélangé la
comptabilité de deux clients.

**2. Montants signés** : `amount_minor > 0` = débit, `< 0` = crédit, jamais zéro.
L'équilibre devient `SUM(amount_minor) = 0`, testable en une ligne.

**3. La devise de l'écriture est celle du compte, par clé étrangère composite :**

```sql
ALTER TABLE ledger_accounts  ADD UNIQUE (id, currency);
ALTER TABLE ledger_entries   ADD FOREIGN KEY (account_id, currency)
                                 REFERENCES ledger_accounts (id, currency);
```

Déclaratif. Pas de trigger à maintenir.

**4. L'équilibre est vérifié au `COMMIT`**, par devise, via un
`CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED`. Les écritures d'une
transaction s'insèrent donc dans n'importe quel ordre, mais la transaction ne
peut pas être validée déséquilibrée. C'est la garantie qui manquait.

**5. Les écritures sont immuables** : `DO INSTEAD NOTHING` sur `UPDATE` et
`DELETE`. Une erreur se corrige par une écriture inverse, jamais par une
réécriture.

**6. Le tiroir-caisse et les soldes sont des vues dérivées du ledger**, pas des
colonnes qu'on incrémente. Les documents avaient raison sur ce point ; il fallait
juste le rendre applicable.

---

## D-11 — Idempotence · VERROUILLÉE

Les documents mettaient `@@unique([tenantId, idempotencyKey])` avec une colonne
nullable. En PostgreSQL ça fonctionne — `NULL ≠ NULL` — mais par accident, ce
n'est écrit nulle part, et le comportement diffère selon le moteur. Une garantie
anti-double-encaissement ne repose pas sur un effet de bord.

```
idempotency_keys(organization_id, key, request_fingerprint,
                 status, response_status, response_body, ...)
  PRIMARY KEY (organization_id, key)
```

`Idempotency-Key` est **obligatoire** sur `POST /orders`, `POST /payments`,
`POST /orders/{id}/refund`.

- clé inconnue → on insère en `IN_PROGRESS`, on traite, on stocke la réponse ;
- clé connue et terminée, même empreinte de requête → on rejoue la réponse ;
- clé connue, **empreinte différente** → `422`. C'est une erreur du client, pas
  un doublon, et la masquer ferait disparaître une vente ;
- clé connue, encore `IN_PROGRESS` → `409`, la caisse réessaiera.

---

## D-12 — Facturation · VERROUILLÉE

Doc 1 §5 rattachait l'abonnement à l'`Organization`. Doc 2 §23 le rattachait au
`tenantId`. Cette contradiction décidait si un commerçant à trois boutiques paie
un abonnement ou trois — c'est-à-dire tout votre chiffre d'affaires.

**Décision : un abonnement par Organization, dont la quantité est le nombre de
Locations actives.**

```
subscriptions(organization_id, plan_price_id, quantity, status,
              current_period_start, current_period_end, grace_until)
UNIQUE (organization_id) WHERE status IN ('TRIALING','ACTIVE','PAST_DUE')
```

Une seule facture, un seul cycle, un prix par site. L'ouverture d'une quatrième
boutique change `quantity` et se proratise. Le client comprend sa facture et
vous avez un seul objet à gérer.

Le modèle `plan_prices` existe (il manquait complètement) et porte devise et
périodicité — c'est ce qui permet de facturer en HTG tout en raisonnant en USD.

---

## D-13 — Marque · VERROUILLÉE

Les documents attribuaient une couleur à cinq produits, **aucune à NexaPOS** —
le produit du MVP — et donnaient à NexaDirectory exactement la couleur de Nexa
Forge.

Il n'y a qu'un seul produit. Il n'y a donc rien à architecturer.

| Token | Valeur |
|---|---|
| `--brand` | `#00B87C` |
| `--ink` | `#111827` |
| `--surface` | `#FFFFFF` |
| `--background` | `#F8FAFC` |

Nom du produit : **Nexa POS**. Éditeur : **Nexa Forge**.

Pas de logo commandé, pas de charte, pas de site vitrine avant la semaine 24.
Une page unique avec un numéro WhatsApp suffit — c'est par là que vos clients
vous contacteront de toute façon.
