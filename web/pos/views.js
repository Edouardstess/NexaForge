/**
 * Écrans gérant : ouverture et clôture de caisse, ventes et remboursements,
 * rapport du jour, conflits de synchronisation.
 *
 * Aucun calcul métier ici — les montants viennent du serveur, qui est seul à
 * savoir ce que dit le ledger. Un écran qui recompte le fond de caisse dans
 * son coin finit par ne plus être d'accord avec la comptabilité.
 */
"use strict";

/* ------------------------------------------------------------ navigation */

const VIEWS = [
  { id: "till", label: "Kès", permission: "order.create" },
  { id: "sales", label: "Vant", permission: "order.read" },
  { id: "report", label: "Rapò", permission: "report.read" },
  { id: "conflicts", label: "Konfli", permission: "conflict.read" },
];

function visibleViews() {
  return VIEWS.filter((v) => can(v.permission));
}

function renderNav() {
  const host = $("#nav");
  if (!host) return;
  host.textContent = "";

  const views = visibleViews();
  if (views.length < 2) return;      // un seul écran : pas de barre

  for (const view of views) {
    const b = el("button", null, view.label);
    b.type = "button";
    b.dataset.view = view.id;
    b.setAttribute("aria-pressed", String(state.view === view.id));
    b.addEventListener("click", () => goTo(view.id));
    host.appendChild(b);
  }
}

function goTo(view) {
  state.view = view;
  renderNav();
  renderView();
}

function renderView() {
  const host = $("#viewhost");
  if (!host) return;

  const till = $("#tillwork");
  if (till) till.hidden = state.view !== "till";

  host.textContent = "";
  host.hidden = state.view === "till";

  if (state.view === "sales") renderSales(host);
  if (state.view === "report") renderReport(host);
  if (state.view === "conflicts") renderConflicts(host);
}

/* --------------------------------------------------------- utilitaires */

function panel(title, subtitle) {
  const p = el("section", "panel");
  const head = el("div", "vhead");
  const titles = el("div");
  titles.appendChild(el("strong", null, title));
  if (subtitle) titles.appendChild(el("div", "shopmeta", subtitle));
  head.appendChild(titles);
  p.appendChild(head);
  return { panel: p, head };
}

function loading(host, label) {
  host.textContent = "";
  host.appendChild(el("p", "empty", label ?? "Ap chaje…"));
}

function kv(label, value, tone) {
  const row = el("div", "kv" + (tone ? " " + tone : ""));
  row.appendChild(el("span", null, label));
  row.appendChild(el("span", "money", value));
  return row;
}

/* =========================================================== OUVERTURE */

function openSessionDialog() {
  const currencies = [baseCurrency(), ...(state.catalog?.exchange_rates ?? []).map((r) => r.quote_currency)];
  const inputs = {};

  modal("Louvri kès la", (body) => {
    body.appendChild(el("p", "hint",
      "Konte lajan ki nan tiwa a kounye a, chak deviz apa. Se sa k ap sèvi baz pou Z la aswè."));

    for (const currency of currencies) {
      const field = el("label", "field");
      field.appendChild(el("span", "lbl", "Fon nan " + (currency === "USD" ? "dola" : "goud")));
      const input = el("input");
      input.type = "text";
      input.inputMode = "decimal";
      input.id = "float-" + currency;
      input.placeholder = "0,00";
      field.appendChild(input);
      inputs[currency] = input;
      body.appendChild(field);
    }
  }, {
    confirm: "Louvri",
    onConfirm: async () => {
      const floats = Object.entries(inputs)
        .map(([currency, input]) => ({ currency, amount_minor: parseMinor(input.value) }))
        .filter((f) => f.amount_minor > 0);

      await openCashierSession(floats.length ? floats : [{ currency: baseCurrency(), amount_minor: 0 }]);
      renderAll();
      toastPlain("Kès la louvri.");
    },
  });
}

/** « 1 250,75 » ou « 1250.75 » → 125075. Jamais de flottant. */
function parseMinor(value) {
  const clean = String(value ?? "").replace(/[\s  ]/g, "").replace(",", ".").trim();
  if (!clean) return 0;
  const m = /^(\d+)(?:\.(\d{0,2}))?$/.exec(clean);
  if (!m) return 0;
  return parseInt(m[1], 10) * 100 + parseInt((m[2] ?? "").padEnd(2, "0"), 10);
}

/* ============================================================ CLÔTURE */

async function closeSessionDialog() {
  const report = await loadSessionReport(state.session.id);
  const inputs = {};

  modal("Fèmen kès la — rapò Z", (body) => {
    const summary = el("div", "zbox");
    summary.appendChild(kv("Vant", String(report.sales.count)));
    summary.appendChild(kv("Total", fmtPlain(report.sales.gross_minor, baseCurrency())));
    summary.appendChild(kv("Taks", fmtPlain(report.sales.tax_minor, baseCurrency())));
    summary.appendChild(kv("Remiz", fmtPlain(report.sales.discount_minor, baseCurrency())));
    body.appendChild(summary);

    if (report.tenders.length) {
      body.appendChild(el("span", "lbl", "Pa mwayen peman"));
      const list = el("div", "zlist");
      for (const t of report.tenders) {
        const row = el("div", "trow");
        row.appendChild(el("span", null, t.method + " · " + t.currency + " × " + t.count));
        row.appendChild(el("span", "money", fmtPlain(t.amount_minor, t.currency)));
        list.appendChild(row);
      }
      body.appendChild(list);
    }

    body.appendChild(el("p", "hint",
      "Konte tiwa a chak deviz apa. Si gen yon ekà, l ap ekri nan kont « ekà de kès » — li pa disparèt."));

    const currencies = report.session.totals.length
      ? report.session.totals.map((t) => t.currency)
      : [baseCurrency()];

    for (const currency of currencies) {
      const field = el("label", "field");
      field.appendChild(el("span", "lbl", "Konte an " + (currency === "USD" ? "dola" : "goud")));
      const input = el("input");
      input.type = "text";
      input.inputMode = "decimal";
      input.id = "count-" + currency;
      input.placeholder = "0,00";
      field.appendChild(input);
      inputs[currency] = input;
      body.appendChild(field);
    }

    const notes = el("label", "field");
    notes.appendChild(el("span", "lbl", "Nòt (fakiltatif)"));
    const area = el("input");
    area.type = "text";
    area.id = "closenotes";
    notes.appendChild(area);
    body.appendChild(notes);
    inputs.__notes = area;
  }, {
    confirm: "Fèmen kès la",
    onConfirm: async () => {
      const counted = Object.entries(inputs)
        .filter(([k]) => k !== "__notes")
        .map(([currency, input]) => ({ currency, amount_minor: parseMinor(input.value) }));

      const result = await closeCashierSession(state.session.id, counted, inputs.__notes.value);
      showVariance(result);
      renderAll();
    },
  });
}

function showVariance(result) {
  const lines = Object.entries(result.totals ?? {});
  const bad = lines.filter(([, t]) => t.variance_minor !== 0);

  if (!bad.length) {
    toastPlain("Kès fèmen — okenn ekà.");
    return;
  }

  alertBox("Kès fèmen ak ekà : " + bad
    .map(([currency, t]) => fmtPlain(t.variance_minor, currency))
    .join(" · "));
}

/* ============================================================== VENTES */

async function renderSales(host) {
  loading(host);

  const { rows } = await loadOrders({ page_size: 30 });
  const { panel: box } = panel("Vant yo", rows.length + " dènye vant");
  host.textContent = "";

  if (!rows.length) {
    box.appendChild(el("p", "empty", "Pa gen vant pou kounye a."));
    host.appendChild(box);
    return;
  }

  const table = el("div", "rows");
  for (const order of rows) {
    const row = el("button", "orow");
    row.type = "button";

    const left = el("div");
    left.appendChild(el("div", "nm money", order.order_number));
    const when = new Date(order.taken_at).toLocaleString("fr-HT", {
      day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit",
    });
    left.appendChild(el("div", "shopmeta", when + (order.origin === "OFFLINE" ? " · san koneksyon" : "")));
    row.appendChild(left);

    const right = el("div", "oright");
    right.appendChild(el("div", "amt money", fmtPlain(order.total_minor, order.currency)));
    if (order.status !== "COMPLETED") {
      right.appendChild(el("span", "badge " + order.status.toLowerCase(),
        order.status === "REFUNDED" ? "ranbouse" : "ranbouse an pati"));
    }
    row.appendChild(right);

    row.addEventListener("click", () => openOrderDialog(order.id));
    table.appendChild(row);
  }

  box.appendChild(table);
  host.appendChild(box);
}

async function openOrderDialog(orderId) {
  const order = await loadOrder(orderId);
  const picks = {};

  // Décidé AVANT de construire le corps : l'objet d'options est évalué en
  // premier, donc le lire depuis `picks` donnerait toujours « vide ».
  const refundable = can("order.refund") && order.status !== "REFUNDED";

  modal("Vant " + order.order_number, (body) => {
    const info = el("div", "zbox");
    info.appendChild(kv("Total", fmtPlain(order.total_minor, order.currency)));
    info.appendChild(kv("Taks", fmtPlain(order.tax_minor, order.currency)));
    if (order.refunded_minor > 0) {
      info.appendChild(kv("Deja ranbouse", fmtPlain(order.refunded_minor, order.currency), "warn"));
    }
    body.appendChild(info);

    body.appendChild(el("span", "lbl", "Atik yo"));
    const list = el("div", "zlist");

    for (const item of order.items) {
      const row = el("div", "iline");
      const label = el("div");
      label.appendChild(el("div", null, item.description));
      label.appendChild(el("div", "shopmeta money",
        item.quantity + " × " + fmtPlain(item.unit_price_minor, order.currency)));
      row.appendChild(label);

      if (refundable) {
        const input = el("input", "qtyin");
        input.type = "number";
        input.min = "0";
        input.step = "1";
        input.max = item.quantity;
        input.placeholder = "0";
        input.id = "refund-" + item.id;
        input.setAttribute("aria-label", "Kantite pou ranbouse — " + item.description);
        picks[item.id] = input;
        row.appendChild(input);
      } else {
        row.appendChild(el("div", "amt money", fmtPlain(item.line_total_minor, order.currency)));
      }

      list.appendChild(row);
    }
    body.appendChild(list);

    body.appendChild(el("span", "lbl", "Peman"));
    const pays = el("div", "zlist");
    for (const p of order.payments) {
      const row = el("div", "trow");
      row.appendChild(el("span", null, p.method + " · " + p.currency));
      row.appendChild(el("span", "money", fmtPlain(p.amount_minor, p.currency)));
      pays.appendChild(row);
    }
    body.appendChild(pays);

    if (!refundable) return;

    const reason = el("label", "field");
    reason.appendChild(el("span", "lbl", "Rezon ranbousman an"));
    const input = el("input");
    input.type = "text";
    input.id = "refundreason";
    input.placeholder = "Kliyan an retounen atik la";
    reason.appendChild(input);
    body.appendChild(reason);
    picks.__reason = input;

    const restock = el("label", "check");
    const box2 = el("input");
    box2.type = "checkbox";
    box2.id = "restock";
    box2.checked = true;
    restock.appendChild(box2);
    restock.appendChild(el("span", null, "Remèt atik la nan stòk"));
    body.appendChild(restock);
    picks.__restock = box2;
  }, {
    confirm: refundable ? "Ranbouse" : null,
    onConfirm: async () => {
      const lines = Object.entries(picks)
        .filter(([k]) => !k.startsWith("__"))
        .map(([id, input]) => ({ order_item_id: id, quantity: String(parseInt(input.value || "0", 10)) }))
        .filter((l) => parseInt(l.quantity, 10) > 0);

      if (!lines.length) throw new Error("Chwazi omwen yon atik pou ranbouse.");
      const reason = (picks.__reason?.value ?? "").trim();
      if (reason.length < 3) throw new Error("Bay yon rezon pou ranbousman an.");

      const refund = await sendRefund(orderId, lines, reason, picks.__restock.checked, "CASH");
      toastPlain("Ranbouse " + fmtPlain(refund.amount_minor, refund.currency));
      renderView();
      await refreshCatalog().catch(() => {});
      renderAll();
    },
  });
}

/* ============================================================= RAPPORT */

async function renderReport(host) {
  loading(host);

  const [day, top] = await Promise.all([loadDailyReport(), loadTopProducts()]);
  host.textContent = "";

  const { panel: box } = panel("Rapò jodi a", day.date + " · " + day.timezone);

  const tiles = el("div", "tiles");
  const tile = (label, value, sub) => {
    const t = el("div", "tile");
    t.appendChild(el("span", "lbl", label));
    t.appendChild(el("span", "big money", value));
    if (sub) t.appendChild(el("span", "shopmeta", sub));
    return t;
  };

  tiles.appendChild(tile("Antre net", fmtPlain(day.net_minor, day.currency),
    day.orders + " vant · panye " + fmtPlain(day.average_basket_minor, day.currency)));
  tiles.appendChild(tile("Marj", fmtPlain(day.margin_minor, day.currency),
    (day.margin_bp / 100).toFixed(2).replace(".", ",") + " %"));
  tiles.appendChild(tile("TCA", fmtPlain(day.tax_minor, day.currency), "pou reverse"));
  tiles.appendChild(tile("Ranbouse", fmtPlain(day.refunded_minor, day.currency),
    day.refunded_minor > 0 ? "gade vant yo" : "anyen"));
  box.appendChild(tiles);

  if (day.tenders.length) {
    box.appendChild(el("span", "lbl pad", "Kijan yo peye"));
    const list = el("div", "zlist pad");
    for (const t of day.tenders) {
      const row = el("div", "trow");
      row.appendChild(el("span", null, t.method + " · " + t.currency + " × " + t.count));
      row.appendChild(el("span", "money", fmtPlain(t.amount_minor, t.currency)));
      list.appendChild(row);
    }
    box.appendChild(list);
  }

  host.appendChild(box);

  const { panel: topBox } = panel("Sa ki pi vann", "30 dènye jou · net apre retou");
  if (!top.length) {
    topBox.appendChild(el("p", "empty", "Poko gen ase vant."));
  } else {
    const list = el("div", "zlist pad");
    for (const p of top) {
      const row = el("div", "trow");
      row.appendChild(el("span", null, p.name + "  ×" + parseFloat(p.quantity)));
      row.appendChild(el("span", "money", fmtPlain(p.revenue_minor, day.currency)));
      list.appendChild(row);
    }
    topBox.appendChild(list);
  }
  host.appendChild(topBox);
}

/* ============================================================ CONFLITS */

async function renderConflicts(host) {
  loading(host);

  const { rows, meta } = await loadConflicts();
  host.textContent = "";

  const { panel: box } = panel("Konfli sinkronizasyon",
    meta.open_total + " bagay pou gade");

  if (!rows.length) {
    box.appendChild(el("p", "empty", "Anyen pou gade. Tout sinkronizasyon pase nèt."));
    host.appendChild(box);
    return;
  }

  const list = el("div", "rows");
  for (const c of rows) {
    const row = el("div", "crow");

    const left = el("div");
    left.appendChild(el("div", "nm", c.explanation));
    const meta2 = [c.order?.number, c.location_code].filter(Boolean).join(" · ");
    left.appendChild(el("div", "shopmeta", meta2));

    // Le détail brut est ce qui permet au gérant de trancher : quel article,
    // quel écart, quel prix.
    const facts = Object.entries(c.details)
      .filter(([k]) => !k.endsWith("_id"))
      .map(([k, v]) => k + " = " + v)
      .join("   ");
    left.appendChild(el("div", "facts money", facts));
    row.appendChild(left);

    if (can("conflict.resolve")) {
      const done = el("button", "quick", "Regle");
      done.type = "button";
      done.addEventListener("click", async () => {
        done.disabled = true;
        await resolveConflict(c.id);
        renderView();
      });
      row.appendChild(done);
    }

    list.appendChild(row);
  }

  box.appendChild(list);
  host.appendChild(box);
}

/* =============================================================== MODAL */

/** Un modal générique : contenu, bouton de confirmation, erreurs affichées. */
function modal(title, build, { confirm, onConfirm } = {}) {
  closeModal();
  veil = el("div", "veil");
  veil.addEventListener("click", (e) => { if (e.target === veil) closeModal(); });

  const box = el("div", "modal");
  box.setAttribute("role", "dialog");
  box.setAttribute("aria-modal", "true");

  const head = el("div", "mhead");
  head.appendChild(el("h2", null, title));
  const x = el("button", "x", "×");
  x.type = "button";
  x.setAttribute("aria-label", "Fèmen");
  x.addEventListener("click", closeModal);
  head.appendChild(x);
  box.appendChild(head);

  const body = el("div", "mbody");
  build(body);
  box.appendChild(body);

  if (confirm) {
    const settle = el("div", "settle");
    const err = el("p", "formerr");
    err.hidden = true;
    err.setAttribute("role", "alert");
    settle.appendChild(err);

    const ok = el("button", "confirm", confirm);
    ok.type = "button";
    ok.addEventListener("click", async () => {
      err.hidden = true;
      ok.disabled = true;
      ok.textContent = "…";
      try {
        await onConfirm();
        closeModal();
      } catch (e) {
        err.textContent = e.offline ? "Pa gen koneksyon." : e.message;
        err.hidden = false;
        ok.disabled = false;
        ok.textContent = confirm;
      }
    });
    settle.appendChild(ok);
    box.appendChild(settle);
  }

  veil.appendChild(box);
  document.body.appendChild(veil);
  document.addEventListener("keydown", onEsc);
}

function toastPlain(message) {
  document.querySelector(".toast")?.remove();
  const box = el("div", "toast");
  box.setAttribute("role", "status");
  box.appendChild(el("span", null, message));
  document.body.appendChild(box);
  setTimeout(() => box.remove(), 4000);
}
