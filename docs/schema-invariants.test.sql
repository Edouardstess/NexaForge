\set ON_ERROR_STOP off
-- fixtures
INSERT INTO organizations (id,name,slug) VALUES
  ('11111111-1111-1111-1111-111111111111','Ti Machann','ti-machann');
INSERT INTO locations (id,organization_id,code,name) VALUES
  ('22222222-2222-2222-2222-222222222222','11111111-1111-1111-1111-111111111111','BTQ1','Boutik Delmas');
INSERT INTO ledger_accounts (id,organization_id,code,name,type,currency) VALUES
  ('aaaaaaaa-0000-0000-0000-000000000001','11111111-1111-1111-1111-111111111111','CASH_DRAWER','Tiroir','ASSET','HTG'),
  ('aaaaaaaa-0000-0000-0000-000000000002','11111111-1111-1111-1111-111111111111','SALES','Ventes','REVENUE','HTG'),
  ('aaaaaaaa-0000-0000-0000-000000000003','11111111-1111-1111-1111-111111111111','CASH_DRAWER','Tiroir USD','ASSET','USD');
INSERT INTO units (id,organization_id,code,name) VALUES
  ('cccccccc-0000-0000-0000-000000000001','11111111-1111-1111-1111-111111111111','UNIT','Inite');
INSERT INTO products (id,organization_id,name) VALUES
  ('dddddddd-0000-0000-0000-000000000001','11111111-1111-1111-1111-111111111111','Kola');
INSERT INTO product_variants (id,organization_id,product_id,unit_id,sku,name) VALUES
  ('eeeeeeee-0000-0000-0000-000000000001','11111111-1111-1111-1111-111111111111','dddddddd-0000-0000-0000-000000000001','cccccccc-0000-0000-0000-000000000001','KOLA-50','50 cl');

\echo '--- TEST 1 : transaction ledger DESEQUILIBREE doit etre REJETEE au COMMIT'
BEGIN;
INSERT INTO ledger_transactions (id,organization_id,reference_type,reference_id,occurred_at)
  VALUES ('ffffffff-0000-0000-0000-000000000001','11111111-1111-1111-1111-111111111111','order','dddddddd-0000-0000-0000-000000000001',now());
INSERT INTO ledger_entries (organization_id,transaction_id,account_id,currency,amount_minor) VALUES
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000001','aaaaaaaa-0000-0000-0000-000000000001','HTG', 50000),
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000001','aaaaaaaa-0000-0000-0000-000000000002','HTG',-49000);
COMMIT;

\echo '--- TEST 2 : transaction EQUILIBREE doit PASSER (ordre d insertion indifferent)'
BEGIN;
INSERT INTO ledger_transactions (id,organization_id,reference_type,reference_id,occurred_at)
  VALUES ('ffffffff-0000-0000-0000-000000000002','11111111-1111-1111-1111-111111111111','order','dddddddd-0000-0000-0000-000000000001',now());
INSERT INTO ledger_entries (organization_id,transaction_id,account_id,currency,amount_minor) VALUES
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000002','aaaaaaaa-0000-0000-0000-000000000001','HTG', 50000),
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000002','aaaaaaaa-0000-0000-0000-000000000002','HTG',-50000);
COMMIT;
SELECT 'TEST 2 OK, ecritures = ' || count(*) FROM ledger_entries;

\echo '--- TEST 3 : devise de l ecriture <> devise du compte doit etre REJETEE'
INSERT INTO ledger_entries (organization_id,transaction_id,account_id,currency,amount_minor) VALUES
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000002','aaaaaaaa-0000-0000-0000-000000000001','USD', 100);

\echo '--- TEST 4 : ecriture ledger IMMUABLE (update et delete sans effet)'
UPDATE ledger_entries SET amount_minor = 999999;
DELETE FROM ledger_entries;
SELECT 'TEST 4 : ecritures restantes = ' || count(*) || ', montant intact = ' ||
       (count(*) FILTER (WHERE abs(amount_minor) = 50000))::text FROM ledger_entries;

\echo '--- TEST 5 : equilibre MULTI-DEVISE verifie PAR DEVISE'
BEGIN;
INSERT INTO ledger_transactions (id,organization_id,reference_type,reference_id,occurred_at)
  VALUES ('ffffffff-0000-0000-0000-000000000003','11111111-1111-1111-1111-111111111111','order','dddddddd-0000-0000-0000-000000000001',now());
INSERT INTO ledger_entries (organization_id,transaction_id,account_id,currency,amount_minor) VALUES
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000003','aaaaaaaa-0000-0000-0000-000000000001','HTG', 50000),
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000003','aaaaaaaa-0000-0000-0000-000000000002','HTG',-50000),
  ('11111111-1111-1111-1111-111111111111','ffffffff-0000-0000-0000-000000000003','aaaaaaaa-0000-0000-0000-000000000003','USD', 500);
COMMIT;

\echo '--- TEST 6 : deux prix qui se CHEVAUCHENT doivent etre REJETES'
INSERT INTO prices (organization_id,product_variant_id,currency,amount_minor,valid_from,valid_to) VALUES
  ('11111111-1111-1111-1111-111111111111','eeeeeeee-0000-0000-0000-000000000001','HTG',5000,'2026-01-01','2026-06-01');
INSERT INTO prices (organization_id,product_variant_id,currency,amount_minor,valid_from,valid_to) VALUES
  ('11111111-1111-1111-1111-111111111111','eeeeeeee-0000-0000-0000-000000000001','HTG',6000,'2026-05-01',NULL);

\echo '--- TEST 6b : prix CONSECUTIFS (sans chevauchement) doivent PASSER'
INSERT INTO prices (organization_id,product_variant_id,currency,amount_minor,valid_from,valid_to) VALUES
  ('11111111-1111-1111-1111-111111111111','eeeeeeee-0000-0000-0000-000000000001','HTG',6000,'2026-06-01',NULL);
SELECT 'TEST 6b OK, prix = ' || count(*) FROM prices;

\echo '--- TEST 6c : meme periode mais devise USD doit PASSER'
INSERT INTO prices (organization_id,product_variant_id,currency,amount_minor,valid_from,valid_to) VALUES
  ('11111111-1111-1111-1111-111111111111','eeeeeeee-0000-0000-0000-000000000001','USD',40,'2026-01-01',NULL);
SELECT 'TEST 6c OK, prix = ' || count(*) FROM prices;

\echo '--- TEST 7 : total de commande incoherent doit etre REJETE'
INSERT INTO users (id,email,password_hash,first_name,last_name) VALUES
  ('99999999-0000-0000-0000-000000000001','k@x.ht','x','Jan','Pyè');
INSERT INTO orders (organization_id,location_id,order_number,currency,subtotal_minor,discount_minor,tax_minor,total_minor,taken_at,created_by)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','A-1','HTG',10000,0,1000,99999,now(),'99999999-0000-0000-0000-000000000001');

\echo '--- TEST 7b : total coherent doit PASSER'
INSERT INTO orders (organization_id,location_id,order_number,currency,subtotal_minor,discount_minor,tax_minor,total_minor,taken_at,created_by,client_order_id)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','A-1','HTG',10000,0,1000,11000,now(),'99999999-0000-0000-0000-000000000001','POS1-000042');
SELECT 'TEST 7b OK, commandes = ' || count(*) FROM orders;

\echo '--- TEST 8 : meme client_order_id (rejeu hors-ligne) doit etre REJETE'
INSERT INTO orders (organization_id,location_id,order_number,currency,subtotal_minor,discount_minor,tax_minor,total_minor,taken_at,created_by,client_order_id)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','A-2','HTG',10000,0,1000,11000,now(),'99999999-0000-0000-0000-000000000001','POS1-000042');

\echo '--- TEST 9 : deux sessions OUVERTES sur la meme caisse doit etre REJETE'
INSERT INTO cashier_sessions (organization_id,location_id,register_code,opened_by,opened_at)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','CAISSE-1','99999999-0000-0000-0000-000000000001',now());
INSERT INTO cashier_sessions (organization_id,location_id,register_code,opened_by,opened_at)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','CAISSE-1','99999999-0000-0000-0000-000000000001',now());

\echo '--- TEST 10 : stock NEGATIF doit etre ACCEPTE (vente hors-ligne, D-09)'
INSERT INTO stock_levels (organization_id,location_id,product_variant_id,quantity)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','eeeeeeee-0000-0000-0000-000000000001',-3);
SELECT 'TEST 10 OK, stock = ' || quantity FROM stock_levels;

\echo '--- TEST 11 : mouvement de stock IMMUABLE'
INSERT INTO stock_movements (organization_id,location_id,product_variant_id,type,quantity)
 VALUES ('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','eeeeeeee-0000-0000-0000-000000000001','SALE',-1);
DELETE FROM stock_movements;
SELECT 'TEST 11 : mouvements restants = ' || count(*) FROM stock_movements;

\echo '--- TEST 12 : aucune colonne monetaire en virgule flottante'
SELECT coalesce(string_agg(table_name||'.'||column_name,', '),'AUCUNE — OK') AS flottants
  FROM information_schema.columns
 WHERE table_schema='public' AND data_type IN ('real','double precision')
   AND (column_name LIKE '%amount%' OR column_name LIKE '%minor%'
        OR column_name LIKE '%price%' OR column_name LIKE '%total%');
