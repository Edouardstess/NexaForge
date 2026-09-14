# Plan d'exécution — Nexa POS

> Hypothèse de charge : **une personne à temps plein**, assistée par IA.
> À temps partiel, multipliez chaque durée par 2,5 — et dites-le-vous
> maintenant, pas dans huit mois.

---

## 1. L'économie, avant le calendrier

C'est ce qui manquait entièrement aux documents : quinze mille mots de
stratégie, aucun chiffre.

### Ce que coûte un client

| Poste | Montant | Qui paie |
|---|---|---|
| Tablette Android 10" | 110 – 130 USD | à trancher |
| Imprimante thermique BT 80 mm | 60 – 90 USD | à trancher |
| Douchette code-barres BT | 30 – 40 USD | à trancher |
| Batterie / onduleur pour 4 h d'autonomie | 50 – 70 USD | à trancher |
| **Matériel par site** | **250 – 330 USD** | |
| Installation + formation sur place (½ journée) | votre temps | vous |

**Décision recommandée : vous prêtez le matériel, la propriété est transférée
après 12 mois d'abonnement payé.** Un commerçant qui doit avancer 300 USD avant
d'avoir vu le logiciel marcher ne signera pas. Ce choix a une conséquence
directe que les documents ne mentionnent jamais :

> **10 clients pilotes = 2 500 à 3 300 USD de matériel immobilisé.**
> C'est votre besoin de financement réel. Pas « lever des fonds » — ça.

### Ce que rapporte un client

Les documents proposaient 35 – 50 USD/mois par point de vente. Pour un
mini-marché de quartier à Port-au-Prince, c'est hors marché. Votre concurrent
n'est pas un autre SaaS : c'est un cahier et une calculatrice, qui coûtent zéro
et ne tombent jamais en panne de courant.

| Offre | Périmètre | Prix |
|---|---|---|
| **Starter** | 1 site, 1 caisse | **15 USD / mois** |
| **Boutique** | 1 site, jusqu'à 3 caisses | **25 USD / mois** |
| **Multi** | par site, à partir de 3 sites | **20 USD / site / mois** |

Facturation en **HTG**, au taux du mois, révisé mensuellement — l'inflation du
taux est un risque que vous portez, pas le commerçant. Ce choix se documente
dans `plan_prices` et se révise ; il ne s'improvise pas chaque mois.

Frais d'installation : **50 USD**, une fois. Ils filtrent les curieux.

### Le point mort, sans enrobage

| Clients | MRR (~20 USD moy.) | Ce que ça couvre |
|---|---|---|
| 10 | 200 USD | l'infrastructure, pas vous |
| 40 | 800 USD | l'infrastructure et un revenu d'appoint |
| **150** | **3 000 USD** | **un revenu correct pour une personne, en Haïti** |
| 400 | 8 000 USD | une petite équipe |

Coûts fixes au départ : VPS + sauvegardes + SMS + outils ≈ **80 – 120 USD/mois**.

**Il faut donc environ 150 commerces payants pour que ce soit votre métier et
non votre projet du soir.** À 4 clients gagnés par mois, c'est trois ans. À 12
par mois, c'est un an. Ce chiffre-là doit gouverner toutes vos décisions : il
dit que le coût d'acquisition et le churn comptent plus que l'architecture.

### La contrainte qui plafonne tout

Une personne peut installer, former et supporter **30 à 40 commerces**. Au-delà,
il faut embaucher, donc il faut la marge, donc il faut le volume. Le produit
doit donc être conçu pour :

- **s'installer sans vous** (auto-inscription, import de catalogue par photo ou
  CSV, assistant de première caisse) ;
- **se diagnostiquer à distance** (logs par site, état de synchronisation
  visible, version du client remontée) ;
- **se supporter par WhatsApp, en créole.**

Ce ne sont pas des options de confort. C'est ce qui décide si vous plafonnez à
35 clients.

---

## 2. Les seize semaines

### Semaines 1 – 2 · Socle

- Dépôt initialisé, CI (lint, analyse statique, tests, vérification de
  migration), `docker compose` (Postgres 16, Redis, MinIO, Mailpit).
- Identité, Organization / Location, RBAC avec `membership_locations`, audit.
- Extraction depuis SchoolFlow de : `Money`, le trait d'isolation, le modèle de
  permissions, les règles `DO INSTEAD NOTHING`, l'interface passerelle de
  paiement. **Extraction, pas fork.**
- Enveloppe d'API uniforme, `request_id`, en-têtes de sécurité, limitation de
  débit.

**Livrable vérifiable** — `docker compose up`, puis créer une organisation, deux
locations, un propriétaire, un caissier restreint à une seule location, et
constater que le caissier reçoit `404` sur l'autre.

### Semaines 3 – 5 · Catalogue, prix, stock

- `units`, `categories`, `tax_rates`, `products`, `product_variants` avec
  `pack_size` / `base_variant_id`.
- `prices` avec la contrainte `EXCLUDE` anti-chevauchement, en HTG et USD.
- `exchange_rates` et le service de conversion.
- `stock_levels` + `stock_movements` + le verrou `FOR UPDATE`.
- Réceptions fournisseur, ajustements, inventaire physique, transferts.
- Job nocturne de réconciliation soldes ↔ mouvements.

**Livrable vérifiable** — importer un catalogue de 500 articles depuis un CSV,
recevoir 5 caisses de 24, constater `+120` unités, changer un prix et vérifier
que la marge d'une vente d'hier n'a pas bougé. Test de concurrence : deux
requêtes simultanées sur le dernier article, une seule passe.

### Semaines 6 – 9 · La caisse

- `cashier_sessions` avec fonds et comptage **par devise**, X et Z.
- `orders`, `order_items` avec prix et coût figés, `customers`.
- `payments` multi-devises avec `fx_rate` gelé, paiements partagés, rendu de
  monnaie, remise, annulation, `refunds`.
- Écritures ledger sur chaque encaissement et chaque clôture.
- Front caisse React : clavier **et** tactile, douchette, impression ESC/POS,
  ticket par SMS.
- Affichage `HTG` / `HTD5` / `USD` selon le réglage de la location.

**Livrable vérifiable** — encaisser 50 ventes d'affilée sans intervention, dont
une payée 1 000 HTG + 5 USD + MonCash, et une remboursée trois jours plus tard
au taux d'origine. Z de caisse à l'écart nul.

### Semaines 10 – 12 · Le hors-ligne

C'est ici qu'est le vrai travail, et c'est ce qui vous différencie.

- SQLite local, outbox, moteur de synchronisation, `idempotency_keys`.
- Les six cas de conflit de **D-09**, avec l'écran gérant qui les affiche.
- Rapports basés sur `taken_at`, jamais sur `created_at`.

**Les cinq tests qui doivent passer, sans exception :**

1. coupure réseau au milieu d'un encaissement ;
2. **coupure de courant, machine éteinte** au milieu d'un encaissement ;
3. 8 h hors-ligne, 200 ventes en attente, puis reconnexion ;
4. deux caisses hors-ligne vendant le même dernier article ;
5. rejeu complet de la file après un échec réseau — zéro doublon, zéro perte.

Le test 2 est celui que tout le monde oublie et celui qui compte le plus ici.

**Livrable vérifiable** — une caisse qui encaisse une journée entière sans
internet et resynchronise sans perdre ni dupliquer une seule vente.

### Semaines 13 – 14 · Exploitation

- Rapports : journalier, X/Z, marge, écarts de stock, meilleures ventes,
  ventes par caissier.
- Abonnement : `plans`, `plan_prices`, `subscriptions`, `invoices`, période de
  grâce, suspension.
- Observabilité : logs structurés avec `request_id` / `organization_id`,
  métriques, alerte sur `sync_failure_rate` et `stock_discrepancies` ouverts.
- Sauvegardes **avec une restauration réellement exécutée**. Une sauvegarde
  jamais restaurée n'est pas une sauvegarde.

**Livrable vérifiable** — diagnostiquer un incident client sans ouvrir `psql`,
et restaurer la base de la veille en moins de 30 minutes, chronomètre en main.

### Semaines 15 – 16 · Pilote

- **3 commerces réels**, gratuits, matériel fourni par vous.
- Vous êtes **physiquement sur place** à l'installation et à la formation.
- Vous regardez le caissier utiliser le logiciel **sans l'aider**. Vous notez
  chaque hésitation. C'est la seule source de vérité sur votre produit.
- Vous mesurez : secondes par vente, appels au support par jour, nombre de fois
  où ils reprennent le cahier.

---

## 3. Les portes de sortie

Elles ne sont pas indicatives. Une porte fermée arrête le programme.

### Semaine 16 — porte produit

> Les 3 pilotes passent-ils **plus de 90 % de leurs ventes** par la caisse,
> 5 jours sur 7, depuis 3 semaines ?

**Non** → le produit n'est pas prêt. Vous ne construisez **rien** d'autre. Vous
corrigez ce que le terrain a montré, et vous repassez la porte.

### Semaine 24 — porte commerciale

> Avez-vous **10 clients payants** ? Pas 10 essais, pas 10 promesses — 10
> commerces qui ont réglé un abonnement **au moins deux fois de suite**.

**Non** → le problème n'est plus technique. Arrêtez de coder. Allez comprendre
pourquoi : le prix, le matériel, la formation, le besoin lui-même.
**Oui** → alors seulement, NexaResto peut sortir de `docs/future/`.

### Semaine 52 — porte entreprise

> 40 clients payants, churn mensuel sous 5 %, MRR au-dessus de 1 500 USD ?

**Oui** → vous avez une entreprise, et le mot « plateforme » commence à mériter
d'être prononcé.
**Non** → vous avez un projet. Ce qui est déjà beaucoup — mais il faut
l'appeler par son nom, parce que les décisions ne sont pas les mêmes.

---

## 4. Definition of Done

Un module n'est pas terminé sans **tout** ce qui suit :

modèle de domaine · services applicatifs · migrations · validation ·
autorisation · endpoints · tests unitaires · tests d'intégration sur PostgreSQL
(jamais SQLite) · audit · événements outbox · logs · gestion d'erreurs.

Pour les fonctionnalités critiques — encaissement, commande, stock,
remboursement, clôture — il faut **en plus** : idempotence, sûreté
transactionnelle, gestion de la concurrence, reprise sur échec, et un test
d'intégration qui coupe le réseau au milieu.

---

## 5. Les interdits

1. **Ne plus écrire de document d'architecture.** Vous en avez pour dix-huit
   mois. Le prochain texte est du code ou une décision de 300 mots.
2. **Ne pas nommer de nouveau produit.** Neuf noms, zéro utilisateur.
3. **Pas de logo commandé, pas de charte, pas de site vitrine** avant la
   semaine 24. Une page et un numéro WhatsApp suffisent.
4. **Pas de microservices, pas de Kubernetes, pas d'event sourcing.** Vos
   propres documents l'avaient écrit (§38, risque 2). Tenez-le.
5. **Pas de `.docx`.** Une seule source de vérité : ce dépôt.
6. **Ne pas rouvrir une décision verrouillée** sans un document qui l'annule
   explicitement. Le coût d'un projet solo n'est pas le code, c'est la
   rediscussion permanente des mêmes choix.
