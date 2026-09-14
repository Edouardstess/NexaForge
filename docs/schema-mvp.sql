-- =============================================================================
-- Nexa POS — schéma PostgreSQL 16 du MVP
--
-- Couvre l'intégralité du périmètre MVP : identité, organisation, RBAC,
-- catalogue, prix, taxes, stock, caisse, commandes, paiements multi-devises,
-- ledger, abonnement, synchronisation hors-ligne, audit.
--
-- Référence : docs/DECISIONS.md. Chaque bloc porte la décision qu'il applique.
-- Ce fichier est la cible. Les migrations Laravel le reproduisent pas à pas.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;     -- gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS citext;       -- emails, slugs insensibles à la casse
CREATE EXTENSION IF NOT EXISTS pg_trgm;      -- recherche produit par nom
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- contrainte EXCLUDE sur les prix

-- =============================================================================
-- 1. IDENTITÉ
-- =============================================================================

CREATE TABLE users (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email           citext UNIQUE,
    phone           text UNIQUE,
    password_hash   text        NOT NULL,
    first_name      text        NOT NULL,
    last_name       text        NOT NULL,
    locale          text        NOT NULL DEFAULT 'ht',
    status          text        NOT NULL DEFAULT 'ACTIVE'
                    CHECK (status IN ('ACTIVE','SUSPENDED','INVITED','DELETED')),
    totp_secret     text,                      -- chiffré applicativement
    totp_confirmed_at timestamptz,
    last_login_at   timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (email IS NOT NULL OR phone IS NOT NULL)   -- en Haïti, le téléphone est
);                                                   -- souvent le seul identifiant

CREATE TABLE login_attempts (
    id           bigserial PRIMARY KEY,
    identifier   citext      NOT NULL,
    ip_address   inet        NOT NULL,
    successful   boolean     NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX login_attempts_lookup
    ON login_attempts (identifier, ip_address, created_at DESC);

-- =============================================================================
-- 2. ORGANISATION ET LOCATIONS                                          [D-03]
--    Le mot « tenant » n'apparaît nulle part. organization_id EST la frontière
--    d'isolation ; location_id est la portée opérationnelle.
-- =============================================================================

CREATE TABLE organizations (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name           text        NOT NULL,
    slug           citext      NOT NULL UNIQUE,
    country        char(2)     NOT NULL DEFAULT 'HT',
    base_currency  char(3)     NOT NULL DEFAULT 'HTG',        -- [D-04]
    timezone       text        NOT NULL DEFAULT 'America/Port-au-Prince',
    status         text        NOT NULL DEFAULT 'ACTIVE'
                   CHECK (status IN ('ACTIVE','SUSPENDED','ARCHIVED')),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE locations (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    code             text        NOT NULL,
    name             text        NOT NULL,
    kind             text        NOT NULL DEFAULT 'STORE'
                     CHECK (kind IN ('STORE','WAREHOUSE')),
    -- Unité d'AFFICHAGE seulement. HTD5 = « dollar haïtien » = 5 HTG. [D-04]
    -- Le stockage reste en centimes de la devise de base, toujours.
    display_unit     text        NOT NULL DEFAULT 'HTG'
                     CHECK (display_unit IN ('HTG','HTD5','USD')),
    address          text,
    latitude         numeric(10,7),
    longitude        numeric(10,7),
    active           boolean     NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, code)
);
CREATE INDEX locations_by_org ON locations (organization_id) WHERE active;

-- =============================================================================
-- 3. RBAC                                                               [D-03]
-- =============================================================================

CREATE TABLE permissions (
    code        text PRIMARY KEY,           -- 'product.update', 'order.refund'
    description text NOT NULL
);

CREATE TABLE roles (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid REFERENCES organizations,   -- NULL = modèle système
    code             text NOT NULL,
    name             text NOT NULL,
    UNIQUE NULLS NOT DISTINCT (organization_id, code)
);

CREATE TABLE role_permissions (
    role_id         uuid NOT NULL REFERENCES roles ON DELETE CASCADE,
    permission_code text NOT NULL REFERENCES permissions,
    PRIMARY KEY (role_id, permission_code)
);

CREATE TABLE memberships (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             uuid        NOT NULL REFERENCES users,
    organization_id     uuid        NOT NULL REFERENCES organizations,
    role_id             uuid        NOT NULL REFERENCES roles,
    status              text        NOT NULL DEFAULT 'ACTIVE'
                        CHECK (status IN ('ACTIVE','SUSPENDED')),
    -- incrémenté à tout changement de droits : invalide le cache sans TTL
    permissions_version integer     NOT NULL DEFAULT 1,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, organization_id)
);

-- LA table qui manquait aux documents : sans elle, un gérant de la boutique A
-- voit la boutique B. Ensemble VIDE = accès à toutes les locations de l'org.
CREATE TABLE membership_locations (
    membership_id uuid NOT NULL REFERENCES memberships ON DELETE CASCADE,
    location_id   uuid NOT NULL REFERENCES locations   ON DELETE CASCADE,
    PRIMARY KEY (membership_id, location_id)
);

CREATE TABLE membership_permission_overrides (
    membership_id   uuid NOT NULL REFERENCES memberships ON DELETE CASCADE,
    permission_code text NOT NULL REFERENCES permissions,
    effect          text NOT NULL CHECK (effect IN ('GRANT','REVOKE')),
    PRIMARY KEY (membership_id, permission_code)
);

-- =============================================================================
-- 4. TAUX DE CHANGE                                                     [D-04]
--    rate = nombre d'unités de base_currency pour 1 unité de quote_currency.
--    base HTG, quote USD, rate 132.50  ⇒  1 USD = 132.50 HTG
-- =============================================================================

CREATE TABLE exchange_rates (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid          NOT NULL REFERENCES organizations,
    base_currency    char(3)       NOT NULL,
    quote_currency   char(3)       NOT NULL,
    rate             numeric(18,8) NOT NULL CHECK (rate > 0),
    effective_from   timestamptz   NOT NULL,
    source           text          NOT NULL DEFAULT 'MANUAL'
                     CHECK (source IN ('MANUAL','BRH','PROVIDER')),
    created_by       uuid REFERENCES users,
    created_at       timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (organization_id, base_currency, quote_currency, effective_from),
    CHECK (base_currency <> quote_currency)
);
CREATE INDEX exchange_rates_current
    ON exchange_rates (organization_id, base_currency, quote_currency,
                       effective_from DESC);

-- =============================================================================
-- 5. CATALOGUE                                                    [D-06, D-07]
-- =============================================================================

CREATE TABLE units (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid     NOT NULL REFERENCES organizations,
    code             text     NOT NULL,          -- 'UNIT', 'KG', 'L', 'CASE'
    name             text     NOT NULL,
    precision        smallint NOT NULL DEFAULT 0 CHECK (precision BETWEEN 0 AND 4),
    UNIQUE (organization_id, code)
);

CREATE TABLE categories (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid NOT NULL REFERENCES organizations,
    parent_id        uuid REFERENCES categories,
    name             text NOT NULL,
    position         integer NOT NULL DEFAULT 0,
    UNIQUE NULLS NOT DISTINCT (organization_id, parent_id, name)
);

CREATE TABLE tax_rates (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid    NOT NULL REFERENCES organizations,
    code             text    NOT NULL,                  -- 'TCA'
    name             text    NOT NULL,
    rate_bp          integer NOT NULL CHECK (rate_bp BETWEEN 0 AND 10000), -- 10 % = 1000
    inclusive        boolean NOT NULL DEFAULT true,     -- Haïti : prix affichés TTC
    active           boolean NOT NULL DEFAULT true,
    UNIQUE (organization_id, code)
);

CREATE TABLE products (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    category_id      uuid REFERENCES categories,
    tax_rate_id      uuid REFERENCES tax_rates,
    name             text        NOT NULL,
    description      text,
    kind             text        NOT NULL DEFAULT 'GOOD'
                     CHECK (kind IN ('GOOD','SERVICE')),
    track_stock      boolean     NOT NULL DEFAULT true,
    active           boolean     NOT NULL DEFAULT true,
    version          integer     NOT NULL DEFAULT 0,     -- verrouillage optimiste
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX products_name_trgm
    ON products USING gin (name gin_trgm_ops);
CREATE INDEX products_by_org ON products (organization_id) WHERE active;

-- pack_size + base_variant_id résolvent « acheter la caisse, vendre l'unité ».
-- Le stock n'est tenu QUE sur la variante de base.
CREATE TABLE product_variants (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid          NOT NULL REFERENCES organizations,
    product_id       uuid          NOT NULL REFERENCES products ON DELETE CASCADE,
    base_variant_id  uuid REFERENCES product_variants,
    unit_id          uuid          NOT NULL REFERENCES units,
    sku              text          NOT NULL,
    barcode          text,
    name             text          NOT NULL,             -- « 50 cl », « Caisse de 24 »
    pack_size        numeric(18,4) NOT NULL DEFAULT 1 CHECK (pack_size > 0),
    active           boolean       NOT NULL DEFAULT true,
    version          integer       NOT NULL DEFAULT 0,
    created_at       timestamptz   NOT NULL DEFAULT now(),
    updated_at       timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (organization_id, sku),
    CHECK (base_variant_id IS NULL OR base_variant_id <> id),
    CHECK (base_variant_id IS NOT NULL OR pack_size = 1)
);
CREATE UNIQUE INDEX product_variants_barcode
    ON product_variants (organization_id, barcode) WHERE barcode IS NOT NULL;
CREATE INDEX product_variants_by_product ON product_variants (product_id);

-- Prix versionnés, sans chevauchement possible.                        [D-06]
CREATE TABLE prices (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id    uuid        NOT NULL REFERENCES organizations,
    product_variant_id uuid        NOT NULL REFERENCES product_variants ON DELETE CASCADE,
    location_id        uuid REFERENCES locations,        -- NULL = prix par défaut
    currency           char(3)     NOT NULL,
    amount_minor       bigint      NOT NULL CHECK (amount_minor >= 0),
    valid_from         timestamptz NOT NULL DEFAULT now(),
    valid_to           timestamptz,
    created_by         uuid REFERENCES users,
    created_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_to IS NULL OR valid_to > valid_from)
);

-- Deux prix valides au même instant pour le même article est un état
-- IMPOSSIBLE au niveau de la base, pas une règle applicative qu'on oublie.
ALTER TABLE prices ADD CONSTRAINT prices_no_overlap EXCLUDE USING gist (
    product_variant_id WITH =,
    (coalesce(location_id, '00000000-0000-0000-0000-000000000000'::uuid)) WITH =,
    currency WITH =,
    tstzrange(valid_from, valid_to) WITH &&
);
CREATE INDEX prices_lookup
    ON prices (product_variant_id, currency, valid_from DESC);

-- =============================================================================
-- 6. STOCK                                                              [D-08]
-- =============================================================================

CREATE TABLE suppliers (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid NOT NULL REFERENCES organizations,
    name             text NOT NULL,
    phone            text,
    email            citext,
    notes            text,
    active           boolean NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now()
);

-- L'ÉTAT COURANT. Lu par la caisse. Verrouillé FOR UPDATE avant toute écriture.
CREATE TABLE stock_levels (
    organization_id    uuid          NOT NULL REFERENCES organizations,
    location_id        uuid          NOT NULL REFERENCES locations,
    product_variant_id uuid          NOT NULL REFERENCES product_variants,
    quantity           numeric(18,4) NOT NULL DEFAULT 0,   -- peut être négatif [D-09]
    reserved           numeric(18,4) NOT NULL DEFAULT 0,
    updated_at         timestamptz   NOT NULL DEFAULT now(),
    PRIMARY KEY (location_id, product_variant_id)
);

-- L'HISTOIRE. Immuable. quantity est signée.
CREATE TABLE stock_movements (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id    uuid          NOT NULL REFERENCES organizations,
    location_id        uuid          NOT NULL REFERENCES locations,
    product_variant_id uuid          NOT NULL REFERENCES product_variants,
    type               text          NOT NULL CHECK (type IN
                       ('PURCHASE','SALE','RETURN','ADJUSTMENT',
                        'TRANSFER_IN','TRANSFER_OUT','WASTE','COUNT')),
    quantity           numeric(18,4) NOT NULL CHECK (quantity <> 0),
    unit_cost_minor    bigint,
    cost_currency      char(3),
    reference_type     text,
    reference_id       uuid,
    reason             text,
    created_by         uuid REFERENCES users,
    created_at         timestamptz   NOT NULL DEFAULT now()
);
CREATE RULE stock_movements_no_update AS ON UPDATE TO stock_movements DO INSTEAD NOTHING;
CREATE RULE stock_movements_no_delete AS ON DELETE TO stock_movements DO INSTEAD NOTHING;
CREATE INDEX stock_movements_history
    ON stock_movements (organization_id, product_variant_id, created_at DESC);
CREATE INDEX stock_movements_by_reference
    ON stock_movements (reference_type, reference_id);

-- Alimentée par le job nocturne de réconciliation soldes ↔ mouvements,
-- et par les conflits de synchronisation hors-ligne.
CREATE TABLE stock_discrepancies (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id    uuid          NOT NULL REFERENCES organizations,
    location_id        uuid          NOT NULL REFERENCES locations,
    product_variant_id uuid          NOT NULL REFERENCES product_variants,
    expected_quantity  numeric(18,4) NOT NULL,
    actual_quantity    numeric(18,4) NOT NULL,
    origin             text          NOT NULL CHECK (origin IN
                       ('RECONCILIATION','OFFLINE_SYNC','PHYSICAL_COUNT')),
    resolved_at        timestamptz,
    resolved_by        uuid REFERENCES users,
    created_at         timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX stock_discrepancies_open
    ON stock_discrepancies (organization_id, created_at DESC)
    WHERE resolved_at IS NULL;

-- =============================================================================
-- 7. CAISSE
-- =============================================================================

CREATE TABLE cashier_sessions (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    location_id      uuid        NOT NULL REFERENCES locations,
    register_code    text        NOT NULL,           -- « CAISSE-1 »
    opened_by        uuid        NOT NULL REFERENCES users,
    opened_at        timestamptz NOT NULL,
    closed_by        uuid REFERENCES users,
    closed_at        timestamptz,
    status           text        NOT NULL DEFAULT 'OPEN'
                     CHECK (status IN ('OPEN','CLOSED','AMENDED')),
    created_at       timestamptz NOT NULL DEFAULT now()
);
-- Une seule session ouverte par caisse physique, garanti par la base.
CREATE UNIQUE INDEX cashier_sessions_one_open
    ON cashier_sessions (location_id, register_code) WHERE status = 'OPEN';

-- Un fonds de caisse et un comptage PAR DEVISE : le tiroir contient des
-- gourdes ET des dollars.                                              [D-04]
CREATE TABLE cashier_session_totals (
    cashier_session_id uuid    NOT NULL REFERENCES cashier_sessions ON DELETE CASCADE,
    currency           char(3) NOT NULL,
    opening_minor      bigint  NOT NULL DEFAULT 0,
    expected_minor     bigint  NOT NULL DEFAULT 0,    -- calculé depuis le ledger
    counted_minor      bigint,                        -- saisi à la clôture
    variance_minor     bigint GENERATED ALWAYS AS (counted_minor - expected_minor) STORED,
    PRIMARY KEY (cashier_session_id, currency)
);

-- =============================================================================
-- 8. COMMANDES ET PAIEMENTS                                       [D-04, D-09]
-- =============================================================================

CREATE TABLE customers (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid NOT NULL REFERENCES organizations,
    name             text NOT NULL,
    phone            text,
    email            citext,
    notes            text,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX customers_phone ON customers (organization_id, phone);
CREATE INDEX customers_name_trgm ON customers USING gin (name gin_trgm_ops);

CREATE TABLE orders (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id    uuid        NOT NULL REFERENCES organizations,
    location_id        uuid        NOT NULL REFERENCES locations,
    cashier_session_id uuid REFERENCES cashier_sessions,
    customer_id        uuid REFERENCES customers,
    order_number       text        NOT NULL,
    status             text        NOT NULL DEFAULT 'DRAFT' CHECK (status IN
                       ('DRAFT','COMPLETED','VOIDED','REFUNDED','PARTIALLY_REFUNDED')),
    currency           char(3)     NOT NULL,          -- devise de comptabilisation
    subtotal_minor     bigint      NOT NULL DEFAULT 0,   -- hors taxe
    discount_minor     bigint      NOT NULL DEFAULT 0,
    tax_minor          bigint      NOT NULL DEFAULT 0,
    total_minor        bigint      NOT NULL DEFAULT 0,
    -- horloge de la CAISSE : peut précéder created_at de plusieurs heures.
    -- Les rapports journaliers se font sur taken_at, jamais sur created_at.
    taken_at           timestamptz NOT NULL,
    synced_at          timestamptz,
    origin             text        NOT NULL DEFAULT 'ONLINE'
                       CHECK (origin IN ('ONLINE','OFFLINE')),
    client_order_id    text,                          -- identifiant local caisse
    created_by         uuid        NOT NULL REFERENCES users,
    version            integer     NOT NULL DEFAULT 0,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, order_number),
    CHECK (total_minor = subtotal_minor - discount_minor + tax_minor)
);
-- Deuxième filet anti-doublon hors-ligne, au niveau de la base.
CREATE UNIQUE INDEX orders_client_id
    ON orders (organization_id, client_order_id) WHERE client_order_id IS NOT NULL;
CREATE INDEX orders_daily ON orders (organization_id, location_id, taken_at DESC);
CREATE INDEX orders_by_session ON orders (cashier_session_id);

CREATE TABLE order_items (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id    uuid          NOT NULL REFERENCES organizations,
    order_id           uuid          NOT NULL REFERENCES orders ON DELETE CASCADE,
    product_variant_id uuid          NOT NULL REFERENCES product_variants,
    description        text          NOT NULL,        -- libellé figé
    quantity           numeric(18,4) NOT NULL CHECK (quantity > 0),
    unit_price_minor   bigint        NOT NULL,        -- hors taxe, figé
    discount_minor     bigint        NOT NULL DEFAULT 0,
    tax_rate_bp        integer       NOT NULL DEFAULT 0,
    tax_minor          bigint        NOT NULL DEFAULT 0,
    line_total_minor   bigint        NOT NULL,
    unit_cost_minor    bigint,                        -- COGS figé → marge stable
    position           integer       NOT NULL,
    UNIQUE (order_id, position)
);
CREATE INDEX order_items_by_order ON order_items (order_id);
CREATE INDEX order_items_by_variant ON order_items (organization_id, product_variant_id);

-- Une commande accepte PLUSIEURS paiements de devises DIFFÉRENTES.     [D-04]
-- fx_rate est gelé ici : une réimpression 3 semaines plus tard rejoue le
-- taux d'origine, pas le taux du jour.
CREATE TABLE payments (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id    uuid          NOT NULL REFERENCES organizations,
    order_id           uuid          NOT NULL REFERENCES orders ON DELETE CASCADE,
    method             text          NOT NULL CHECK (method IN
                       ('CASH','MONCASH','NATCASH','CARD','CREDIT')),
    currency           char(3)       NOT NULL,        -- devise RÉELLEMENT reçue
    amount_minor       bigint        NOT NULL CHECK (amount_minor > 0),
    fx_rate            numeric(18,8) NOT NULL DEFAULT 1 CHECK (fx_rate > 0),
    base_amount_minor  bigint        NOT NULL,        -- converti, devise de la commande
    tendered_minor     bigint,                        -- présenté par le client
    change_minor       bigint,                        -- rendu, en devise de base
    provider_reference text,
    status             text          NOT NULL DEFAULT 'SETTLED' CHECK (status IN
                       ('PENDING','SETTLED','FAILED','REVERSED')),
    received_at        timestamptz   NOT NULL,
    created_at         timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (organization_id, method, provider_reference)
);
CREATE INDEX payments_by_order ON payments (order_id);

CREATE TABLE refunds (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    order_id         uuid        NOT NULL REFERENCES orders,
    payment_id       uuid REFERENCES payments,
    amount_minor     bigint      NOT NULL CHECK (amount_minor > 0),
    currency         char(3)     NOT NULL,
    fx_rate          numeric(18,8) NOT NULL DEFAULT 1,  -- celui de la vente d'origine
    reason           text        NOT NULL,
    restock          boolean     NOT NULL DEFAULT true,
    created_by       uuid        NOT NULL REFERENCES users,
    created_at       timestamptz NOT NULL DEFAULT now()
);

-- =============================================================================
-- 9. LEDGER                                                             [D-10]
--    amount_minor signé : > 0 débit, < 0 crédit. Équilibre ⇒ SUM = 0.
-- =============================================================================

CREATE TABLE ledger_accounts (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid    NOT NULL REFERENCES organizations,
    location_id      uuid REFERENCES locations,
    code             text    NOT NULL,     -- CASH_DRAWER, SALES, TCA_PAYABLE,
    name             text    NOT NULL,     -- MONCASH, COGS, INVENTORY, FX_GAIN_LOSS
    type             text    NOT NULL CHECK (type IN
                     ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE')),
    currency         char(3) NOT NULL,
    active           boolean NOT NULL DEFAULT true,
    UNIQUE (organization_id, code, currency),
    UNIQUE (id, currency)       -- support de la clé étrangère composite ci-dessous
);

CREATE TABLE ledger_transactions (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    reference_type   text        NOT NULL,      -- 'order', 'refund', 'session_close'
    reference_id     uuid        NOT NULL,
    description      text,
    occurred_at      timestamptz NOT NULL,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ledger_transactions_by_reference
    ON ledger_transactions (organization_id, reference_type, reference_id);

CREATE TABLE ledger_entries (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid    NOT NULL REFERENCES organizations,   -- manquait aux docs
    transaction_id   uuid    NOT NULL REFERENCES ledger_transactions ON DELETE RESTRICT,
    account_id       uuid    NOT NULL,
    currency         char(3) NOT NULL,                            -- manquait aux docs
    amount_minor     bigint  NOT NULL CHECK (amount_minor <> 0),
    created_at       timestamptz NOT NULL DEFAULT now(),
    -- La devise de l'écriture EST celle du compte. Déclaratif, pas de trigger.
    FOREIGN KEY (account_id, currency) REFERENCES ledger_accounts (id, currency)
);
CREATE INDEX ledger_entries_by_account ON ledger_entries (account_id, created_at DESC);
CREATE INDEX ledger_entries_by_transaction ON ledger_entries (transaction_id);

CREATE RULE ledger_entries_no_update AS ON UPDATE TO ledger_entries DO INSTEAD NOTHING;
CREATE RULE ledger_entries_no_delete AS ON DELETE TO ledger_entries DO INSTEAD NOTHING;

-- L'invariant que les documents énonçaient sans mécanisme.
-- Vérifié au COMMIT, par devise : les écritures peuvent donc s'insérer dans
-- n'importe quel ordre, mais la transaction ne peut PAS être validée
-- déséquilibrée.
CREATE OR REPLACE FUNCTION ledger_assert_balanced() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM ledger_entries e
         WHERE e.transaction_id = NEW.transaction_id
         GROUP BY e.currency
        HAVING sum(e.amount_minor) <> 0
    ) THEN
        RAISE EXCEPTION
            'ledger transaction % is unbalanced', NEW.transaction_id
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER ledger_entries_balanced
    AFTER INSERT ON ledger_entries
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_balanced();

-- =============================================================================
-- 10. IDEMPOTENCE                                                       [D-11]
-- =============================================================================

CREATE TABLE idempotency_keys (
    organization_id     uuid        NOT NULL REFERENCES organizations,
    key                 text        NOT NULL,
    request_fingerprint text        NOT NULL,      -- sha256 du corps normalisé
    status              text        NOT NULL CHECK (status IN ('IN_PROGRESS','COMPLETED')),
    response_status     integer,
    response_body       jsonb,
    locked_at           timestamptz,
    completed_at        timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (organization_id, key)
);
CREATE INDEX idempotency_keys_cleanup ON idempotency_keys (created_at);

-- =============================================================================
-- 11. SYNCHRONISATION HORS-LIGNE                                        [D-09]
-- =============================================================================

CREATE TABLE sync_conflicts (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    location_id      uuid        NOT NULL REFERENCES locations,
    order_id         uuid REFERENCES orders,
    kind             text        NOT NULL CHECK (kind IN
                     ('STOCK_NEGATIVE','PRICE_VARIANCE','ARCHIVED_PRODUCT',
                      'EXPIRED_DISCOUNT','SESSION_CLOSED')),
    details          jsonb       NOT NULL,
    resolved_at      timestamptz,
    resolved_by      uuid REFERENCES users,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sync_conflicts_open
    ON sync_conflicts (organization_id, created_at DESC) WHERE resolved_at IS NULL;

-- =============================================================================
-- 12. OUTBOX ET AUDIT
-- =============================================================================

CREATE TABLE outbox_events (
    id               bigserial PRIMARY KEY,
    organization_id  uuid REFERENCES organizations,
    event_type       text        NOT NULL,
    aggregate_type   text        NOT NULL,
    aggregate_id     uuid        NOT NULL,
    payload          jsonb       NOT NULL,
    occurred_at      timestamptz NOT NULL,
    published_at     timestamptz,
    attempts         integer     NOT NULL DEFAULT 0,
    last_error       text,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX outbox_events_unpublished
    ON outbox_events (occurred_at) WHERE published_at IS NULL;

CREATE TABLE audit_logs (
    id               bigserial PRIMARY KEY,
    organization_id  uuid REFERENCES organizations,
    location_id      uuid REFERENCES locations,
    user_id          uuid REFERENCES users,
    action           text        NOT NULL,
    entity_type      text        NOT NULL,
    entity_id        uuid,
    before_data      jsonb,
    after_data       jsonb,
    ip_address       inet,
    user_agent       text,
    request_id       text,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE RULE audit_logs_no_update AS ON UPDATE TO audit_logs DO INSTEAD NOTHING;
CREATE RULE audit_logs_no_delete AS ON DELETE TO audit_logs DO INSTEAD NOTHING;
CREATE INDEX audit_logs_by_org ON audit_logs (organization_id, created_at DESC);
CREATE INDEX audit_logs_by_entity ON audit_logs (entity_type, entity_id);

-- =============================================================================
-- 13. ABONNEMENT                                                        [D-12]
--     Un abonnement par Organization. quantity = nombre de Locations actives.
-- =============================================================================

CREATE TABLE plans (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code        text NOT NULL UNIQUE,          -- 'STARTER', 'BOUTIQUE', 'MULTI'
    name        text NOT NULL,
    features    jsonb NOT NULL DEFAULT '{}'::jsonb,
    active      boolean NOT NULL DEFAULT true
);

-- Le modèle qui manquait complètement aux documents : c'est lui qui permet de
-- facturer en HTG tout en raisonnant en USD.
CREATE TABLE plan_prices (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    plan_id        uuid        NOT NULL REFERENCES plans,
    currency       char(3)     NOT NULL,
    interval       text        NOT NULL CHECK (interval IN ('MONTH','YEAR')),
    amount_minor   bigint      NOT NULL CHECK (amount_minor >= 0),
    per_location   boolean     NOT NULL DEFAULT true,
    min_locations  integer     NOT NULL DEFAULT 1,
    valid_from     timestamptz NOT NULL DEFAULT now(),
    valid_to       timestamptz
);

CREATE TABLE subscriptions (
    id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id      uuid        NOT NULL REFERENCES organizations,
    plan_price_id        uuid        NOT NULL REFERENCES plan_prices,
    quantity             integer     NOT NULL DEFAULT 1 CHECK (quantity > 0),
    status               text        NOT NULL CHECK (status IN
                         ('TRIALING','ACTIVE','PAST_DUE','SUSPENDED','CANCELLED')),
    current_period_start timestamptz NOT NULL,
    current_period_end   timestamptz NOT NULL,
    grace_until          timestamptz,
    cancel_at            timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX subscriptions_one_live
    ON subscriptions (organization_id)
    WHERE status IN ('TRIALING','ACTIVE','PAST_DUE');

CREATE TABLE invoices (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  uuid        NOT NULL REFERENCES organizations,
    subscription_id  uuid REFERENCES subscriptions,
    number           text        NOT NULL,
    currency         char(3)     NOT NULL,
    subtotal_minor   bigint      NOT NULL,
    tax_minor        bigint      NOT NULL DEFAULT 0,
    total_minor      bigint      NOT NULL,
    paid_minor       bigint      NOT NULL DEFAULT 0,
    status           text        NOT NULL CHECK (status IN
                     ('DRAFT','OPEN','PAID','VOID','UNCOLLECTIBLE')),
    due_at           timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, number),
    CHECK (total_minor = subtotal_minor + tax_minor),
    CHECK (paid_minor >= 0 AND paid_minor <= total_minor)
);

CREATE TABLE invoice_lines (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    invoice_id   uuid          NOT NULL REFERENCES invoices ON DELETE CASCADE,
    description  text          NOT NULL,
    quantity     numeric(18,4) NOT NULL,
    unit_minor   bigint        NOT NULL,
    total_minor  bigint        NOT NULL
);

-- =============================================================================
-- 14. GARDE-FOU
--     Aucune colonne monétaire ne doit être en virgule flottante.       [D-05]
--     Ce test tourne en CI.
-- =============================================================================
--
--   SELECT table_name, column_name, data_type
--     FROM information_schema.columns
--    WHERE table_schema = 'public'
--      AND data_type IN ('real','double precision')
--      AND (column_name LIKE '%amount%' OR column_name LIKE '%minor%'
--           OR column_name LIKE '%price%' OR column_name LIKE '%total%');
--   -- doit retourner 0 ligne
