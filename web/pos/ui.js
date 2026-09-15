/** Rendu de la caisse. Aucune logique métier ici : elle vit dans app.js. */
"use strict";

const $ = (s) => document.querySelector(s);
const el = (tag, cls, text) => {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text !== undefined) n.textContent = text;
  return n;
};

/* ------------------------------------------------------------ connexion */

function renderLogin(message) {
  document.body.textContent = "";
  const shell = el("div", "shell");
  const box = el("div", "panel login");

  const head = el("div", "mhead");
  head.appendChild(el("h2", null, "Konekte nan kès la"));
  box.appendChild(head);

  const form = el("form", "mbody");
  form.id = "loginform";

  const idField = el("label", "field");
  idField.appendChild(el("span", "lbl", "Imèl oswa telefòn"));
  const idInput = el("input");
  idInput.id = "identifier"; idInput.name = "identifier";
  idInput.autocomplete = "username"; idInput.required = true;
  idInput.value = "kesye@nexa.test";
  idField.appendChild(idInput);

  const pwField = el("label", "field");
  pwField.appendChild(el("span", "lbl", "Modpas"));
  const pwInput = el("input");
  pwInput.id = "password"; pwInput.name = "password";
  pwInput.type = "password"; pwInput.autocomplete = "current-password"; pwInput.required = true;
  pwField.appendChild(pwInput);

  form.appendChild(idField);
  form.appendChild(pwField);

  if (message) {
    const err = el("p", "formerr", message);
    err.setAttribute("role", "alert");
    form.appendChild(err);
  }

  const submit = el("button", "confirm", "Antre");
  submit.type = "submit"; submit.id = "signin";
  form.appendChild(submit);

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    submit.disabled = true;
    submit.textContent = "Y ap konekte…";
    try {
      const res = await api("/auth/login", {
        method: "POST",
        body: {
          identifier: idInput.value.trim(),
          password: pwInput.value,
          device_name: "Kès web",
        },
      });
      state.token = res.data.token;
      write(STORE.token, state.token);
      await boot();
    } catch (err) {
      renderLogin(err.offline ? "Pa gen koneksyon ak sèvè a." : err.message);
    }
  });

  box.appendChild(form);
  shell.appendChild(box);
  document.body.appendChild(shell);
  idInput.focus();
}

/* -------------------------------------------------------------- écran */

function renderShell() {
  document.body.textContent = "";
  const shell = el("div", "shell");
  shell.id = "shell";

  /* barre */
  const bar = el("header", "bar");
  const mark = el("div", "brandmark");
  mark.appendChild(el("div", "nf", "NF"));
  const names = el("div");
  names.appendChild(el("div", "shopname", state.me?.organization?.name ?? "Nexa POS"));
  const meta = el("div", "shopmeta");
  meta.id = "sessionmeta";
  names.appendChild(meta);
  mark.appendChild(names);
  bar.appendChild(mark);

  const net = el("button", "pill");
  net.id = "net"; net.type = "button";
  net.appendChild(el("span", "dot"));
  net.appendChild(el("span", null, "")).id = "netlabel";
  net.title = "Eta koneksyon an";
  bar.appendChild(net);

  const seg = el("div", "seg");
  seg.setAttribute("role", "group");
  seg.setAttribute("aria-label", "Inite afichaj");
  for (const [unit, label] of [["HTG", "Goud"], ["HTD5", "Dola ayisyen"], ["USD", "USD"]]) {
    const b = el("button", null, label);
    b.type = "button"; b.dataset.unit = unit;
    b.setAttribute("aria-pressed", String(state.unit === unit));
    b.addEventListener("click", () => {
      state.unit = unit;
      seg.querySelectorAll("button").forEach((x) =>
        x.setAttribute("aria-pressed", String(x.dataset.unit === unit)));
      renderAll();
      if ($(".veil")) renderModal();
    });
    seg.appendChild(b);
  }
  bar.appendChild(seg);

  // Ouvrir / fermer la caisse depuis la barre : c'est le premier et le
  // dernier geste de la journée, il ne se cache pas dans un menu.
  const sess = el("button", "pill");
  sess.id = "sessbtn"; sess.type = "button";
  sess.addEventListener("click", () => {
    if (state.session) closeSessionDialog().catch((e) => alertBox(e.message));
    else openSessionDialog();
  });
  bar.appendChild(sess);

  const out = el("button", "pill", "Soti");
  out.type = "button";
  out.addEventListener("click", async () => {
    await api("/auth/logout", { method: "POST" }).catch(() => {});
    signOut();
    renderLogin();
  });
  bar.appendChild(out);
  shell.appendChild(bar);

  const nav = el("nav", "nav");
  nav.id = "nav";
  nav.setAttribute("aria-label", "Ekran yo");
  shell.appendChild(nav);

  // Les écrans gérant se rendent ici ; la caisse garde sa propre grille.
  const viewhost = el("div", "viewhost");
  viewhost.id = "viewhost";
  viewhost.hidden = true;
  shell.appendChild(viewhost);

  /* corps */
  const work = el("div", "work");
  work.id = "tillwork";

  const left = el("section", "panel");
  const searchrow = el("div", "searchrow");
  const search = el("label", "search");
  search.appendChild(el("span", null, "⌕")).style.color = "var(--ink-faint)";
  const input = el("input");
  input.id = "q"; input.type = "search"; input.autocomplete = "off";
  input.placeholder = "Chèche yon atik oswa tape kòd la…";
  input.addEventListener("input", () => { state.query = input.value; renderGrid(); });
  search.appendChild(input);
  searchrow.appendChild(search);
  left.appendChild(searchrow);

  const chips = el("div", "chips");
  chips.id = "cats";
  chips.setAttribute("role", "group");
  left.appendChild(chips);

  const grid = el("div", "grid");
  grid.id = "grid";
  left.appendChild(grid);
  work.appendChild(left);

  /* ticket */
  const ticket = el("aside", "panel ticket");
  const thead = el("div", "thead");
  thead.appendChild(el("strong", null, "Tikè a"));
  const tno = el("span", "tno money");
  tno.id = "tno";
  thead.appendChild(tno);
  ticket.appendChild(thead);

  const lines = el("div", "lines");
  lines.id = "lines";
  ticket.appendChild(lines);

  const tt = el("div", "totals");
  for (const [id, label] of [["sub", "Sou-total (san taks)"], ["tax", "Taks"]]) {
    const row = el("div", "trow");
    row.appendChild(el("span", null, label));
    const v = el("span", "money", "—"); v.id = id;
    row.appendChild(v);
    tt.appendChild(row);
  }
  const grand = el("div", "trow grand");
  grand.appendChild(el("span", null, "Total"));
  const gv = el("span", "money", "—"); gv.id = "tot";
  grand.appendChild(gv);
  tt.appendChild(grand);
  const alt = el("div", "alt"); alt.id = "alt";
  tt.appendChild(alt);
  ticket.appendChild(tt);

  const pay = el("button", "pay", "Peye");
  pay.id = "paybtn"; pay.type = "button"; pay.disabled = true;
  pay.addEventListener("click", openModal);
  ticket.appendChild(pay);

  work.appendChild(ticket);
  shell.appendChild(work);

  const queue = el("div", "queue");
  queue.id = "queue"; queue.hidden = true;
  shell.appendChild(queue);

  document.body.appendChild(shell);
}

function renderChrome() {
  const net = $("#net");
  if (!net) return;

  net.className = "pill" + (state.online ? "" : " off");
  const label = net.querySelector("span:last-child");
  if (label) label.textContent = state.online ? "Anliy" : "San koneksyon";

  const meta = $("#sessionmeta");
  if (meta) {
    meta.textContent = state.session
      ? state.session.register_code + " · " + (state.me?.user?.first_name ?? "") + " · sesyon louvri"
      : "Pa gen sesyon louvri";
  }

  const sess = $("#sessbtn");
  if (sess) {
    sess.textContent = state.session ? "Fèmen kès la" : "Louvri kès la";
    sess.className = "pill" + (state.session ? "" : " off");
  }

  const queue = $("#queue");
  if (queue) {
    queue.hidden = state.queue.length === 0;
    queue.textContent = "";
    if (state.queue.length) {
      queue.appendChild(el("span", null, "⇅"));
      const t = el("span");
      const b = el("b", null, String(state.queue.length));
      t.appendChild(b);
      t.appendChild(document.createTextNode(
        " vant nan fil la — y ap voye otomatikman lè koneksyon an tounen."));
      queue.appendChild(t);
    }
  }
}

function renderGrid() {
  const grid = $("#grid");
  const cats = $("#cats");
  if (!grid || !cats) return;

  const items = state.catalog?.items ?? [];
  const categories = ["Tout", ...[...new Set(items.map((i) => i.category).filter(Boolean))]];

  cats.textContent = "";
  for (const c of categories) {
    const b = el("button", "chip", c);
    b.type = "button";
    b.setAttribute("aria-pressed", String(state.category === c));
    b.addEventListener("click", () => { state.category = c; renderGrid(); });
    cats.appendChild(b);
  }

  const q = state.query.toLowerCase();
  const visible = items.filter((i) =>
    (state.category === "Tout" || i.category === state.category) &&
    (!q || (i.name + " " + i.variant_name + " " + (i.sku ?? "") + " " + (i.barcode ?? ""))
      .toLowerCase().includes(q)));

  grid.textContent = "";

  if (!visible.length) {
    grid.appendChild(el("p", "empty", items.length
      ? "Pa gen atik ki matche « " + state.query + " »."
      : "Katalòg la vid. Tcheke koneksyon an."));
    return;
  }

  for (const item of visible) {
    const b = el("button", "item");
    b.type = "button";
    b.disabled = item.price_minor === null;
    b.appendChild(el("span", "nm", item.name));
    b.appendChild(el("span", "sz", item.variant_name));
    if (!item.tax_rate_bp) b.appendChild(el("span", "tca0", "SAN TCA"));
    b.appendChild(el("span", "pr money", item.price_minor === null ? "san pri" : fmt(item.price_minor)));

    const left = parseFloat(item.stock);
    const st = el("span", "stk" + (left < 0 ? " neg" : left <= 5 ? " low" : ""));
    st.textContent = left < 0 ? "Stòk " + left + " — gen yon ekà"
      : left <= 5 ? "Rete " + left + " sèlman"
      : left + " an stòk";
    b.appendChild(st);

    b.addEventListener("click", () => addToCart(item.variant_id));
    grid.appendChild(b);
  }
}

function renderTicket() {
  const box = $("#lines");
  if (!box) return;
  box.textContent = "";

  if (!state.cart.length) {
    box.appendChild(el("p", "noline", "Klike sou yon atik pou kòmanse."));
  }

  for (const line of state.cart) {
    const item = itemFor(line.variant_id);
    if (!item) continue;

    const row = el("div", "line");
    row.appendChild(el("div", "nm", item.name + " · " + item.variant_name));
    row.appendChild(el("div", "amt money", fmt(item.price_minor * line.quantity)));

    const sub = el("div", "sub");
    const qty = el("span", "qty");
    const minus = el("button", null, "−");
    minus.type = "button";
    minus.setAttribute("aria-label", "Retire youn " + item.name);
    minus.addEventListener("click", () => bump(line.variant_id, -1));
    const count = el("span", "money", String(line.quantity));
    const plus = el("button", null, "+");
    plus.type = "button";
    plus.setAttribute("aria-label", "Ajoute youn " + item.name);
    plus.addEventListener("click", () => bump(line.variant_id, 1));
    qty.appendChild(minus); qty.appendChild(count); qty.appendChild(plus);

    sub.appendChild(qty);
    sub.appendChild(el("span", "money", "× " + fmt(item.price_minor)));
    const rm = el("button", "rm", "Retire");
    rm.type = "button";
    rm.addEventListener("click", () => removeLine(line.variant_id));
    sub.appendChild(rm);

    row.appendChild(sub);
    box.appendChild(row);
  }

  const t = totals();
  $("#sub").textContent = state.cart.length ? fmt(t.sub) : "—";
  $("#tax").textContent = state.cart.length ? fmt(t.tax) : "—";
  $("#tot").textContent = state.cart.length ? fmt(t.tot) : "—";

  const usdRate = rateFor("USD");
  $("#alt").textContent = state.cart.length && state.unit !== "USD" && usdRate
    ? "≈ $" + (Math.round((t.tot * 1e8) / Math.round(parseFloat(usdRate) * 1e8)) / 100).toFixed(2)
    : "";

  const pay = $("#paybtn");
  pay.disabled = !state.cart.length || !state.session;
  pay.textContent = state.cart.length ? "Peye  " + fmt(t.tot) : "Peye";

  $("#tno").textContent = state.session ? state.session.register_code : "—";
}

function renderAll() {
  if (!$("#shell")) renderShell();
  renderChrome();
  renderGrid();
  renderTicket();
  renderNav();
  renderView();
}

/* -------------------------------------------------------------- modal */

let veil = null;

function openModal() {
  closeModal();
  state.tenders = [];
  veil = el("div", "veil");
  veil.addEventListener("click", (e) => { if (e.target === veil) closeModal(); });
  document.body.appendChild(veil);
  document.addEventListener("keydown", onEsc);
  renderModal();
}

function closeModal() {
  if (veil) { veil.remove(); veil = null; }
  document.removeEventListener("keydown", onEsc);
}

function onEsc(e) { if (e.key === "Escape") closeModal(); }

function renderModal() {
  if (!veil) return;
  const t = totals();
  const got = collected();
  const rest = Math.max(0, t.tot - got);
  const change = Math.max(0, got - t.tot);

  veil.textContent = "";
  const modal = el("div", "modal");
  modal.setAttribute("role", "dialog");
  modal.setAttribute("aria-modal", "true");

  const head = el("div", "mhead");
  head.appendChild(el("h2", null, "Ankese vant lan"));
  const x = el("button", "x", "×");
  x.type = "button";
  x.setAttribute("aria-label", "Fèmen");
  x.addEventListener("click", closeModal);
  head.appendChild(x);
  modal.appendChild(head);

  const due = el("div", "due");
  due.appendChild(el("span", "lbl", "Kliyan an dwe"));
  due.appendChild(el("span", "money", fmt(t.tot)));
  modal.appendChild(due);

  const body = el("div", "mbody");

  const usdRate = rateFor("USD");
  if (usdRate) {
    const rate = el("div", "rate");
    rate.appendChild(el("span", null, "To jodi a — jele sou chak peman"));
    rate.appendChild(el("strong", "money",
      "1 USD = " + parseFloat(usdRate).toFixed(2).replace(".", ",") + " G"));
    body.appendChild(rate);
  }

  const addTender = (method, currency, minor) => {
    if (minor <= 0) return;
    state.tenders.push({ method, currency, amount_minor: minor });
    renderModal();
  };

  const block = (label, cls, buttons) => {
    const wrap = el("div", "meth");
    wrap.appendChild(el("span", "lbl", label));
    const row = el("div", "mrow");
    for (const [text, fn] of buttons) {
      const b = el("button", "quick" + (cls ? " " + cls : ""), text);
      b.type = "button";
      b.addEventListener("click", fn);
      row.appendChild(b);
    }
    wrap.appendChild(row);
    return wrap;
  };

  body.appendChild(block("Kach an goud", "", [
    ["Egzak", () => addTender("CASH", baseCurrency(), Math.max(0, t.tot - collected()))],
    ["500 G", () => addTender("CASH", baseCurrency(), 50000)],
    ["1 000 G", () => addTender("CASH", baseCurrency(), 100000)],
    ["2 000 G", () => addTender("CASH", baseCurrency(), 200000)],
  ]));

  if (usdRate) {
    body.appendChild(block("Kach an dola", "usd", [
      ["$5", () => addTender("CASH", "USD", 500)],
      ["$10", () => addTender("CASH", "USD", 1000)],
      ["$20", () => addTender("CASH", "USD", 2000)],
      ["$50", () => addTender("CASH", "USD", 5000)],
    ]));
  }

  body.appendChild(block("Lajan mobil", "", [
    ["MonCash", () => addTender("MONCASH", baseCurrency(), Math.max(0, t.tot - collected()))],
    ["NatCash", () => addTender("NATCASH", baseCurrency(), Math.max(0, t.tot - collected()))],
  ]));

  if (state.tenders.length) {
    const list = el("div", "meth");
    list.appendChild(el("span", "lbl", "Sa ki resevwa"));
    state.tenders.forEach((td, i) => {
      const row = el("div", "tender" + (td.currency === "USD" ? " isusd" : ""));
      row.appendChild(el("span", "tag",
        td.currency === "USD" ? "USD" : (td.method === "CASH" ? "GOUD" : td.method)));
      row.appendChild(el("strong", "money", fmtPlain(td.amount_minor, td.currency)));
      if (td.currency !== baseCurrency()) {
        row.appendChild(el("span", "conv money",
          "= " + fmtPlain(toBase(td.amount_minor, td.currency) ?? 0, baseCurrency())));
      }
      const drop = el("button", "drop", "×");
      drop.type = "button";
      drop.setAttribute("aria-label", "Retire peman sa a");
      drop.addEventListener("click", () => { state.tenders.splice(i, 1); renderModal(); });
      row.appendChild(drop);
      list.appendChild(row);
    });
    body.appendChild(list);
  }

  modal.appendChild(body);

  const settle = el("div", "settle");
  if (rest > 0) {
    const r = el("div", "sline rest");
    r.appendChild(el("span", null, "Rete pou peye"));
    r.appendChild(el("span", "money", fmtPlain(rest, baseCurrency())));
    settle.appendChild(r);
  } else {
    const c = el("div", "sline chg");
    c.appendChild(el("span", null, "Monnen pou rann"));
    c.appendChild(el("span", "money", fmtPlain(change, baseCurrency())));
    settle.appendChild(c);
  }

  const ok = el("button", "confirm",
    state.online ? "Fini vant lan" : "Fini vant lan (kenbe lokal)");
  ok.type = "button"; ok.id = "confirmsale";
  ok.disabled = rest > 0 || !state.tenders.length;
  ok.addEventListener("click", () => { ok.disabled = true; completeSale(); });
  settle.appendChild(ok);

  modal.appendChild(settle);
  veil.appendChild(modal);
}

/* -------------------------------------------------------------- toasts */

let toastTimer = null;

function toast(orderNumber, total, change, queued, orderId) {
  document.querySelector(".toast")?.remove();
  clearTimeout(toastTimer);

  const box = el("div", "toast");
  box.setAttribute("role", "status");
  box.appendChild(el("span", "no money", orderNumber));
  box.appendChild(el("span", "sep"));
  box.appendChild(el("span", "money", fmtPlain(total, baseCurrency())));
  if (change > 0) {
    box.appendChild(el("span", "sep"));
    box.appendChild(el("span", null, "monnen " + fmtPlain(change, baseCurrency())));
  }
  if (queued) {
    box.appendChild(el("span", "sep"));
    box.appendChild(el("span", null, "kenbe lokal"));
  }
  // Imprimer depuis le toast : c'est le moment où le client attend son
  // papier, pas trois écrans plus loin.
  if (orderId) {
    const print = el("button", "tprint", "Resi");
    print.type = "button";
    print.addEventListener("click", () => showReceipt(orderId).catch((e) => alertBox(e.message)));
    box.appendChild(el("span", "sep"));
    box.appendChild(print);
    toastTimer = setTimeout(() => box.remove(), 12000);
  } else {
    toastTimer = setTimeout(() => box.remove(), 5000);
  }

  document.body.appendChild(box);
}

function alertBox(message) {
  document.querySelector(".toast")?.remove();
  const box = el("div", "toast bad");
  box.setAttribute("role", "alert");
  box.appendChild(el("span", null, message));
  document.body.appendChild(box);
  setTimeout(() => box.remove(), 6000);
}

/* ------------------------------------------------------------ démarrage */

window.addEventListener("online", () => setOnline(true));
window.addEventListener("offline", () => setOnline(false));
document.addEventListener("DOMContentLoaded", boot);
