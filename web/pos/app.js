/**
 * Caisse Nexa POS — client connecté à l'API.
 *
 * Deux règles gouvernent ce fichier, et elles viennent des décisions :
 *
 *  1. Une vente encaissée n'est jamais perdue. Si le réseau tombe au moment
 *     de l'envoi, la vente part dans une file locale et repart plus tard.
 *     Chaque vente porte une clé d'idempotence STABLE, créée avant le premier
 *     envoi et réutilisée à chaque tentative, plus un client_order_id : deux
 *     filets indépendants contre le double encaissement.          [D-09, D-11]
 *
 *  2. Pas de flottant sur l'argent. Tout est en centimes entiers, et les
 *     calculs d'affichage reproduisent exactement ceux du serveur.     [D-05]
 */
"use strict";

const API = window.NEXA_API ?? "http://127.0.0.1:8000/api/v1";
const STORE = {
  token: "nexa.token",
  org: "nexa.org",
  queue: "nexa.queue",
  session: "nexa.session",
  catalog: "nexa.catalog",
};

/* ------------------------------------------------------------------ état */

const state = {
  token: read(STORE.token),
  organizationId: read(STORE.org),
  me: null,
  session: readJSON(STORE.session),
  catalog: readJSON(STORE.catalog) ?? null,
  cart: [],
  tenders: [],
  queue: readJSON(STORE.queue) ?? [],
  online: navigator.onLine,
  locationId: null,
  registerCode: "CAISSE-1",
  lastOrder: null,
  view: "till",
  unit: "HTG",
  category: "Tout",
  query: "",
  busy: false,
};

function read(k) { try { return localStorage.getItem(k); } catch { return null; } }
function write(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch {} }
function readJSON(k) { try { return JSON.parse(localStorage.getItem(k) ?? "null"); } catch { return null; } }
function writeJSON(k, v) { write(k, v === null ? null : JSON.stringify(v)); }

/* ------------------------------------------------------- arithmétique */
// Reproduit TaxCalculator et ExchangeRateService, au centime près.

const BP = 10000;

function proportion(minor, num, den) {
  const mag = Math.floor((Math.abs(minor) * num * 2 + den) / (den * 2));
  return minor < 0 ? -mag : mag;
}

function splitTax(ttc, rateBp, inclusive) {
  if (!rateBp) return { base: ttc, tax: 0 };
  if (!inclusive) return { base: ttc, tax: proportion(ttc, rateBp, BP) };
  const base = proportion(ttc, BP, BP + rateBp);
  return { base, tax: ttc - base };
}

function rateFor(currency) {
  const r = (state.catalog?.exchange_rates ?? []).find((x) => x.quote_currency === currency);
  return r ? r.rate : null;
}

function toBase(minor, currency) {
  if (currency === baseCurrency()) return minor;
  const rate = rateFor(currency);
  if (rate === null) return null;
  // Échelle 8, comme côté serveur.
  return Math.round((minor * Math.round(parseFloat(rate) * 1e8)) / 1e8);
}

function baseCurrency() { return state.catalog?.base_currency ?? state.me?.organization?.base_currency ?? "HTG"; }

/* ------------------------------------------------------------ affichage */

function fmt(minor) {
  if (minor === null || minor === undefined) return "—";
  if (state.unit === "USD") {
    const rate = rateFor("USD");
    if (rate === null) return "—";
    return "$" + (Math.round((minor * 1e8) / Math.round(parseFloat(rate) * 1e8)) / 100).toFixed(2);
  }
  const m = state.unit === "HTD5" ? Math.round(minor / 5) : minor;
  const s = (Math.abs(m) / 100).toFixed(2).replace(".", ",").replace(/\B(?=(\d{3})+(?!\d))/g, " ");
  return (m < 0 ? "-" : "") + s + (state.unit === "HTD5" ? " D" : " G");
}

function fmtPlain(minor, currency) {
  const s = (Math.abs(minor) / 100).toFixed(2).replace(".", ",").replace(/\B(?=(\d{3})+(?!\d))/g, " ");
  return (minor < 0 ? "-" : "") + s + (currency === "USD" ? " $" : " G");
}

/* ----------------------------------------------------------------- API */

async function api(path, { method = "GET", body, idempotencyKey, timeout = 12000 } = {}) {
  const headers = { Accept: "application/json" };
  if (state.token) headers.Authorization = "Bearer " + state.token;
  if (state.organizationId) headers["X-Organization"] = state.organizationId;
  if (body) headers["Content-Type"] = "application/json";
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;

  const control = new AbortController();
  const timer = setTimeout(() => control.abort(), timeout);

  try {
    const res = await fetch(API + path, {
      method, headers,
      body: body ? JSON.stringify(body) : undefined,
      signal: control.signal,
    });
    const payload = await res.json().catch(() => null);

    if (!res.ok) {
      const err = new Error(payload?.error?.message ?? "Erè sèvè a (" + res.status + ")");
      err.code = payload?.error?.code;
      err.status = res.status;
      err.details = payload?.error?.details;
      throw err;
    }

    setOnline(true);
    return { data: payload?.data, meta: payload?.meta, replayed: res.headers.get("Idempotent-Replay") === "true" };
  } catch (e) {
    // Un abort ou un échec réseau, c'est « pas de réseau ». Une 4xx/5xx est
    // une réponse du serveur : le réseau va bien, la requête non.
    if (e.name === "AbortError" || e instanceof TypeError) {
      setOnline(false);
      const offline = new Error("Pa gen koneksyon.");
      offline.offline = true;
      throw offline;
    }
    throw e;
  } finally {
    clearTimeout(timer);
  }
}

function setOnline(value) {
  if (state.online === value) return;
  state.online = value;
  renderChrome();
  if (value) flushQueue();
}

/* ------------------------------------------------------------- panier */

function itemFor(variantId) {
  return (state.catalog?.items ?? []).find((i) => i.variant_id === variantId);
}

function addToCart(variantId) {
  const line = state.cart.find((l) => l.variant_id === variantId);
  if (line) line.quantity += 1;
  else state.cart.push({ variant_id: variantId, quantity: 1 });
  renderAll();
}

function bump(variantId, delta) {
  const line = state.cart.find((l) => l.variant_id === variantId);
  if (!line) return;
  if (line.quantity + delta <= 0) return removeLine(variantId);
  line.quantity += delta;
  renderAll();
}

function removeLine(variantId) {
  state.cart = state.cart.filter((l) => l.variant_id !== variantId);
  renderAll();
}

function totals() {
  let sub = 0, tax = 0, tot = 0;
  for (const line of state.cart) {
    const item = itemFor(line.variant_id);
    if (!item || item.price_minor === null) continue;
    const ttc = item.price_minor * line.quantity;
    const s = splitTax(ttc, item.tax_rate_bp, item.tax_inclusive);
    sub += s.base; tax += s.tax; tot += ttc;
  }
  return { sub, tax, tot };
}

function collected() {
  return state.tenders.reduce((a, t) => a + (toBase(t.amount_minor, t.currency) ?? 0), 0);
}

/* ------------------------------------------------- encaissement + file */

function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
  });
}

/**
 * Construit la vente puis tente de l'envoyer.
 *
 * La clé d'idempotence et le client_order_id sont fixés AVANT le premier
 * envoi et ne changent plus : c'est ce qui rend une nouvelle tentative sûre.
 */
async function completeSale() {
  const t = totals();
  const sale = {
    idempotency_key: uuid(),
    body: {
      cashier_session_id: state.session.id,
      client_order_id: state.session.register_code + "-" + uuid().slice(0, 8),
      taken_at: new Date().toISOString(),
      origin: state.online ? "ONLINE" : "OFFLINE",
      lines: state.cart.map((l) => {
        const item = itemFor(l.variant_id);
        return {
          variant_id: l.variant_id,
          quantity: String(l.quantity),
          // Le prix que le client a réellement payé part avec la vente : le
          // serveur n'a pas à le redeviner six heures plus tard.      [D-09]
          unit_price_minor: item.price_minor,
        };
      }),
      tenders: state.tenders.map((x) => ({
        method: x.method,
        currency: x.currency,
        amount_minor: x.amount_minor,
      })),
    },
    expected_total: t.tot,
    change: Math.max(0, collected() - t.tot),
    queued_at: new Date().toISOString(),
  };

  // ÉCRITURE D'ABORD, ENVOI ENSUITE.
  //
  // La vente est posée sur le disque AVANT la moindre tentative réseau. Si le
  // courant saute pendant l'envoi — le cas le plus fréquent à Port-au-Prince,
  // avant même la coupure réseau — elle est déjà là et repartira au
  // redémarrage. L'inverse perdrait une vente encaissée.              [D-09]
  enqueue(sale);

  state.cart = [];
  state.tenders = [];
  closeModal();
  renderAll();

  try {
    const res = await send(sale);
    dequeue(sale.idempotency_key);
    state.lastOrder = { id: res.data.id, number: res.data.order_number };
    toast(res.data.order_number, sale.expected_total, sale.change, false, state.lastOrder.id);
  } catch (e) {
    if (e.offline) {
      // Elle reste dans la file, exactement où on l'a mise.
      toast("nan fil la", sale.expected_total, sale.change, true);
    } else {
      dequeue(sale.idempotency_key);
      alertBox(e.message);
    }
  }

  await refreshCatalog().catch(() => {});
  renderAll();
}

async function send(sale, { replay = false } = {}) {
  // Une vente rejouée depuis la file n'est pas arrivée en direct : c'est,
  // par définition, une vente hors-ligne. Le dire ouvre les tolérances que
  // le serveur réserve à ce cas — prix disparu, session déjà close. [D-09]
  const body = replay ? { ...sale.body, origin: "OFFLINE" } : sale.body;

  return api("/orders", { method: "POST", body, idempotencyKey: sale.idempotency_key });
}

function enqueue(sale) {
  state.queue.push(sale);
  // Écriture synchrone : au retour de cette ligne, la vente est sur le
  // disque. Un await ici rouvrirait la fenêtre qu'on vient de fermer.
  writeJSON(STORE.queue, state.queue);
  renderChrome();
}

function dequeue(idempotencyKey) {
  state.queue = state.queue.filter((s) => s.idempotency_key !== idempotencyKey);
  writeJSON(STORE.queue, state.queue);
  renderChrome();
}

/** Rejoue la file dans l'ordre. Un échec réseau arrête la boucle sans perte. */
async function flushQueue() {
  if (!state.queue.length || state.busy) return;
  state.busy = true;

  while (state.queue.length) {
    const sale = state.queue[0];
    try {
      await send(sale, { replay: true });
      dequeue(sale.idempotency_key);
    } catch (e) {
      if (e.offline) break;               // on réessaiera, rien n'est perdu
      // Refus définitif du serveur : on sort la vente de la file et on la
      // signale, plutôt que de boucler indéfiniment dessus.
      dequeue(sale.idempotency_key);
      alertBox("Yon vant nan fil la refize : " + e.message);
    }
  }

  state.busy = false;
  renderChrome();
  await refreshCatalog().catch(() => {});
  renderAll();
}

/* ------------------------------------------------------------ démarrage */

async function boot() {
  if (!state.token) return renderLogin();

  try {
    const me = await api("/me");
    state.me = me.data;
    state.organizationId = me.data.organization.id;
    write(STORE.org, state.organizationId);
  } catch (e) {
    if (!e.offline) { signOut(); return renderLogin(); }
    // Hors-ligne au démarrage : on travaille sur le dernier catalogue connu.
  }

  await ensureSession();
  await refreshCatalog().catch(() => {});
  renderAll();
  installScanner();
  flushQueue();
}

async function ensureSession() {
  if (!state.me) return;

  // /me rend les locations RÉSOLUES : une portée vide veut dire « toutes »,
  // et le client ne doit pas avoir à le deviner.
  const locations = state.me.locations ?? [];

  if (!locations.length) {
    alertBox("Okenn pwen vant pa asiyen pou kont sa a.");
    return;
  }

  state.locationId = locations[0].id;

  try {
    const current = await api("/cashier-sessions/current?location_id=" + state.locationId);
    if (current.data) {
      state.session = current.data;
      writeJSON(STORE.session, state.session);
      return;
    }
  } catch (e) {
    if (e.offline) return;   // on reprend la session connue du stockage local
    throw e;
  }

  // On n'ouvre plus la caisse à la place du caissier : le fond de tiroir est
  // un chiffre qu'il compte, pas un zéro qu'on suppose. L'écran propose
  // l'ouverture, il ne la fait pas dans son dos.
}

async function refreshCatalog() {
  const locationId = state.session?.location_id ?? state.locationId ?? state.catalog?.location_id;
  if (!locationId) return;

  const res = await api("/catalog/snapshot?location_id=" + locationId);
  state.catalog = res.data;
  writeJSON(STORE.catalog, state.catalog);
}

function signOut() {
  state.token = null; state.me = null; state.session = null;
  write(STORE.token, null); write(STORE.org, null); write(STORE.session, null);
}

/* ==================================================================== */
/*  Écrans gérant : caisse, ventes, rapports, conflits                  */
/* ==================================================================== */

/** Ce que le membre a le droit de voir, pour n'afficher que ça. */
function can(permission) {
  return (state.me?.permissions ?? []).includes(permission);
}

async function openCashierSession(floats) {
  const res = await api("/cashier-sessions", {
    method: "POST",
    body: {
      location_id: state.locationId,
      register_code: state.registerCode ?? "CAISSE-1",
      opening: floats,
    },
  });
  state.session = res.data;
  writeJSON(STORE.session, state.session);
  return res.data;
}

async function loadSessionReport(sessionId) {
  return (await api("/cashier-sessions/" + sessionId + "/report")).data;
}

/**
 * Clôture. Le comptage part PAR DEVISE : le tiroir contient des gourdes et
 * des dollars, et un Z qui les additionne ne veut rien dire.           [D-04]
 */
async function closeCashierSession(sessionId, counted, notes) {
  const res = await api("/cashier-sessions/" + sessionId + "/close", {
    method: "POST",
    body: { counted, notes: notes || null },
  });
  state.session = null;
  write(STORE.session, null);
  return res.data;
}

async function loadOrders(params = {}) {
  const q = new URLSearchParams(params).toString();
  const res = await api("/orders" + (q ? "?" + q : ""));
  return { rows: res.data, meta: res.meta };
}

async function loadOrder(id) {
  return (await api("/orders/" + id)).data;
}

async function sendRefund(orderId, lines, reason, restock, method) {
  return (await api("/orders/" + orderId + "/refund", {
    method: "POST",
    idempotencyKey: uuid(),
    body: { lines, reason, restock, method },
  })).data;
}

async function loadDailyReport(date) {
  const q = new URLSearchParams({ location_id: state.locationId });
  if (date) q.set("date", date);
  return (await api("/reports/daily?" + q)).data;
}

async function loadTopProducts() {
  return (await api("/reports/top-products?limit=10")).data;
}

async function loadConflicts() {
  const res = await api("/sync/conflicts");
  return { rows: res.data, meta: res.meta };
}

async function resolveConflict(id) {
  return api("/sync/conflicts/" + id + "/resolve", { method: "POST" });
}

/* ==================================================================== */
/*  Douchette et ticket                                                 */
/* ==================================================================== */

/**
 * Une douchette bon marché se présente au système comme un CLAVIER : elle
 * tape le code puis Entrée, très vite. On la reconnaît à sa vitesse — un
 * humain ne tape pas dix caractères en moins de cinquante millisecondes
 * chacun — ce qui évite d'exiger un pilote ou une permission.
 */
function installScanner() {
  const MAX_GAP_MS = 60;
  const MIN_LENGTH = 4;

  let buffer = "";
  let lastKey = 0;

  document.addEventListener("keydown", (e) => {
    const now = Date.now();

    // Une saisie lente est celle d'un humain : on ne s'en mêle pas.
    if (now - lastKey > MAX_GAP_MS) buffer = "";
    lastKey = now;

    if (e.key === "Enter") {
      const code = buffer;
      buffer = "";
      if (code.length >= MIN_LENGTH) {
        e.preventDefault();
        onScan(code);
      }
      return;
    }

    if (e.key.length === 1) buffer += e.key;
  });
}

function onScan(code) {
  const items = state.catalog?.items ?? [];
  const found = items.find((i) => i.barcode === code)
    ?? items.find((i) => i.sku?.toUpperCase() === code.toUpperCase());

  if (!found) {
    alertBox("Kòd " + code + " pa nan katalòg la.");
    return;
  }

  if (found.price_minor === null) {
    alertBox(found.name + " pa gen pri. Mete youn anvan w vann li.");
    return;
  }

  addToCart(found.variant_id);
}

/** Le ticket, tel que le serveur le met en page. */
async function fetchReceipt(orderId, format = "text") {
  const headers = { Authorization: "Bearer " + state.token };
  if (state.organizationId) headers["X-Organization"] = state.organizationId;

  const res = await fetch(API + "/orders/" + orderId + "/receipt?format=" + format, { headers });
  if (!res.ok) throw new Error("Resi a pa disponib.");

  return format === "escpos" ? res.arrayBuffer() : res.text();
}
