// Plantillas HTML: cada una imita un tipo de programa de facturación real
// (Word de autónomo, ERP con cuadro de impuestos, factura web moderna,
// factura de suministros, ticket de TPV...). Se pintan en A4 con páginas
// explícitas para que el PDF y las fotos tengan el mismo aspecto.

import { formatDate, formatMoney, formatNumber, formatRate } from './money.mjs';

const esc = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

const TITLES = {
    es: { invoice: 'FACTURA', simplified_invoice: 'FACTURA SIMPLIFICADA', credit_note: 'FACTURA RECTIFICATIVA', delivery_note: 'ALBARÁN DE ENTREGA', quote: 'PRESUPUESTO', proforma: 'FACTURA PROFORMA' },
    en: { invoice: 'INVOICE', credit_note: 'CREDIT NOTE', quote: 'QUOTE' },
    pt: { invoice: 'FATURA', credit_note: 'NOTA DE CRÉDITO', quote: 'ORÇAMENTO' },
};

function title(inv) {
    if (inv.proforma) {
        return TITLES.es.proforma;
    }
    return TITLES[inv.lang]?.[inv.kind] ?? TITLES.es[inv.kind] ?? 'FACTURA';
}

function helpers(inv) {
    const s = inv.style;
    return {
        m: (cents) => formatMoney(cents, s.money, inv.currency),
        n: (value, decimals = 2) => formatNumber(value, decimals, s.money === 'en' ? 'en' : s.money),
        d: (iso) => formatDate(iso, s.date),
        r: (rate) => formatRate(rate, s.money === 'en' ? 'en' : 'es'),
        L: s.labels,
    };
}

function qty(h, value) {
    return Number.isInteger(value) ? String(value) : h.n(value, 2);
}

function unitPrice(h, line) {
    return h.n(line.unit / 100, line.unitDecimals ?? (Number.isInteger(line.unit) ? 2 : 3));
}

/** Reparte las líneas en páginas: la última deja sitio para los totales. */
function paginate(lines, first, rest, lastReserve) {
    const pages = [];
    let remaining = [...lines];
    let capacity = first;

    while (remaining.length > 0) {
        const fitsWithTotals = remaining.length <= capacity - lastReserve;
        const take = fitsWithTotals ? remaining.length : Math.min(remaining.length, capacity);
        pages.push(remaining.slice(0, take));
        remaining = remaining.slice(take);
        capacity = rest;
        if (remaining.length === 0 && !fitsWithTotals) {
            pages.push([]);
        }
    }

    return pages.length > 0 ? pages : [[]];
}

function address(party) {
    if (!party?.address) {
        return '';
    }
    const a = party.address;
    return `${esc(a.street)}<br>${esc([a.zip, a.city].filter(Boolean).join(' '))}${a.province && a.province !== a.city ? ` (${esc(a.province)})` : ''}`;
}

function taxIdLine(inv, h, party) {
    if (!party?.taxId) {
        return '';
    }
    const label = inv.lang === 'en' ? 'VAT ID' : inv.lang === 'pt' ? 'NIF' : h.L.taxId;
    return `${label}: ${esc(party.printedTaxId ?? party.taxId)}`;
}

const BASE_CSS = `
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; }
    .page { width: 210mm; height: 297mm; padding: 14mm 15mm; position: relative; overflow: hidden; page-break-after: always; break-after: page; background: #fff; }
    .page:last-child { page-break-after: auto; break-after: auto; }
    table { border-collapse: collapse; width: 100%; }
    .num { text-align: right; white-space: nowrap; }
    .muted { color: #666; }
    .pageno { position: absolute; bottom: 8mm; right: 15mm; font-size: 8pt; color: #777; }
    .legal { position: absolute; bottom: 8mm; left: 15mm; right: 40mm; font-size: 6.5pt; color: #888; line-height: 1.3; }
`;

function doc(css, pages) {
    return `<!doctype html><html lang="es"><head><meta charset="utf-8"><style>${BASE_CSS}${css}</style></head><body>${pages.join('')}</body></html>`;
}

function linesTable(inv, h, lines, { codes = false, showRate = false, cls = 'lines' } = {}) {
    const prices = inv.kind !== 'delivery_note' || inv.showPrices;
    const head = `<tr>${codes ? '<th>Ref.</th>' : ''}<th class="desc">${esc(h.L.description)}</th><th class="num">${esc(h.L.qty)}</th>${prices ? `<th class="num">${esc(h.L.price)}</th>` : ''}${prices && lines.some((l) => l.discount) ? '<th class="num">Dto.</th>' : ''}${showRate && prices ? '<th class="num">IVA</th>' : ''}${prices ? `<th class="num">${esc(h.L.amount)}</th>` : ''}</tr>`;
    const anyDiscount = lines.some((l) => l.discount);
    const rows = lines.map((line) => `<tr>${codes ? `<td>${esc(line.code ?? '')}</td>` : ''}<td class="desc">${esc(line.description)}</td><td class="num">${line.qty === undefined ? '' : qty(h, line.qty)}${line.unitLabel && line.unitLabel !== 'ud' ? ` ${esc(line.unitLabel)}` : ''}</td>${prices ? `<td class="num">${line.unit === undefined ? '' : unitPrice(h, line)}</td>` : ''}${prices && anyDiscount ? `<td class="num">${line.discount ? `${line.discount} %` : ''}</td>` : ''}${showRate && prices ? `<td class="num">${h.r(line.rate)}</td>` : ''}${prices ? `<td class="num">${h.m(line.amount)}</td>` : ''}</tr>`).join('');
    return `<table class="${cls}"><thead>${head}</thead><tbody>${rows}</tbody></table>`;
}

/** Bloque de totales "Base / IVA x % / Recargo / Retención / Total". */
function totals(inv, calc, h) {
    const rows = [];
    const multi = calc.breakdown.length > 1;

    if (inv.globalDiscount) {
        rows.push([h.L.base === 'Subtotal' ? 'Suma' : 'Subtotal', h.m(calc.gross)]);
        rows.push([`${inv.globalDiscountLabel} ${inv.globalDiscount} %`, h.m(-calc.breakdown.reduce((s, r) => s + r.discount, 0))]);
    }

    if (multi) {
        for (const row of calc.breakdown) {
            rows.push([`${h.L.base} ${h.r(row.rate)}`, h.m(row.base)]);
        }
        for (const row of calc.breakdown) {
            rows.push([`${h.L.tax} ${h.r(row.rate)}`, h.m(row.tax)]);
        }
    } else {
        const row = calc.breakdown[0];
        rows.push([h.L.base, h.m(row.base)]);
        rows.push([row.rate === 0 ? `${h.L.tax} 0 % (exento)` : `${h.L.tax} ${h.r(row.rate)}`, h.m(row.tax)]);
    }

    for (const row of calc.breakdown.filter((r) => r.surcharge)) {
        rows.push([`Recargo equiv. ${h.r(row.surchargeRate)}`, h.m(row.surcharge)]);
    }

    if (calc.withholding) {
        rows.push([`Retención IRPF ${h.r(inv.withholdingRate)}`, h.m(-calc.withholding)]);
    }

    return { rows, total: h.m(calc.printedTotal) };
}

/** Cuadro de impuestos típico de ERP español: una fila por tipo. */
function taxGrid(inv, calc, h) {
    const surcharge = calc.breakdown.some((r) => r.surcharge);
    const head = `<tr><th class="num">${esc(h.L.base)}</th><th class="num">% IVA</th><th class="num">${esc(h.L.tax)}</th>${surcharge ? '<th class="num">% R.E.</th><th class="num">Cuota R.E.</th>' : ''}</tr>`;
    const rows = calc.breakdown.map((r) => `<tr><td class="num">${h.m(r.base)}</td><td class="num">${h.n(r.rate, 2)}</td><td class="num">${h.m(r.tax)}</td>${surcharge ? `<td class="num">${h.n(r.surchargeRate, 2)}</td><td class="num">${h.m(r.surcharge)}</td>` : ''}</tr>`).join('');
    return `<table class="taxgrid"><thead>${head}</thead><tbody>${rows}</tbody></table>`;
}

function partyBlock(inv, h, party, heading) {
    if (!party) {
        return '';
    }
    return `<div class="party"><div class="ph">${esc(heading)}</div><strong>${esc(party.name)}</strong><br>${party.commercialName && inv.customer === party ? `${esc(party.commercialName)}<br>` : ''}${address(party)}<br>${taxIdLine(inv, h, party)}</div>`;
}

function supplierHeader(inv, h) {
    const s = inv.supplier;
    const brand = s.commercialName ?? s.name;
    return `<div class="brand">${esc(brand)}</div>${s.commercialName ? `<div class="legalname">${esc(s.name)}</div>` : ''}<div class="sinfo">${address(s)}<br>${taxIdLine(inv, h, s)}${s.phone ? ` · Tel. ${esc(s.phone)}` : ''}${s.email ? `<br>${esc(s.email)}` : ''}</div>`;
}

function meta(inv, h) {
    const rows = [[h.L.number, inv.number], [h.L.date, h.d(inv.issueDate)]];
    if (inv.dueDate) {
        rows.push([h.L.due, h.d(inv.dueDate)]);
    }
    if (inv.rectifies) {
        rows.push(['Rectifica a', `${inv.rectifies.number} (${h.d(inv.rectifies.date)})`]);
    }
    if (inv.vehicle) {
        rows.push(['Matrícula', inv.vehicle]);
    }
    return rows;
}

function notesHtml(inv) {
    const notes = [...inv.notes];
    if (inv.rectifies) {
        notes.unshift(`Motivo de la rectificación: ${inv.rectifies.reason}.`);
    }
    if (inv.payment && !['delivery_note'].includes(inv.kind)) {
        notes.push(inv.payment);
    }
    return notes.map((note) => `<p>${esc(note)}</p>`).join('');
}

// ─── Clásica: serif, cajas con borde, totales en recuadro ────────────────

function classic(inv, calc) {
    const h = helpers(inv);
    const pages = paginate(calc.lines, 16, 30, 6);
    const t = totals(inv, calc, h);
    const css = `
        body { font-family: 'Liberation Serif', 'DejaVu Serif', serif; font-size: 10.5pt; color: #111; }
        .top { display: flex; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 8px; }
        .brand { font-size: 19pt; font-weight: bold; letter-spacing: .5px; }
        .legalname { font-size: 9pt; }
        .sinfo { font-size: 9pt; margin-top: 4px; line-height: 1.35; }
        .title { font-size: 22pt; letter-spacing: 3px; text-align: right; }
        .meta td { padding: 1px 0 1px 12px; font-size: 10pt; }
        .meta td:first-child { font-weight: bold; }
        .party { border: 1px solid #111; padding: 8px 10px; width: 52%; margin: 14px 0 14px auto; font-size: 10pt; line-height: 1.4; }
        .ph { font-size: 8.5pt; text-transform: uppercase; color: #444; margin-bottom: 3px; }
        .lines th { border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 5px 6px; font-size: 9.5pt; text-align: left; }
        .lines td { padding: 4px 6px; border-bottom: 1px dotted #aaa; font-size: 10pt; vertical-align: top; }
        .lines th.num, .lines td.num { text-align: right; }
        .tot { width: 46%; margin: 16px 0 0 auto; border: 1px solid #111; }
        .tot td { padding: 4px 8px; }
        .tot tr.grand td { border-top: 2px solid #111; font-weight: bold; font-size: 12.5pt; }
        .notes { margin-top: 16px; font-size: 9pt; } .notes p { margin: 2px 0; }
    `;

    const html = pages.map((lines, i) => {
        const last = i === pages.length - 1;
        return `<section class="page">
            <div class="top"><div>${supplierHeader(inv, h)}</div><div><div class="title">${title(inv)}</div><table class="meta">${meta(inv, h).map(([k, v]) => `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join('')}</table></div></div>
            ${i === 0 ? partyBlock(inv, h, inv.customer, h.L.customer) : '<div style="height:10px"></div>'}
            ${lines.length ? linesTable(inv, h, lines, { codes: !!lines[0].code && inv.template === 'classic' && false }) : ''}
            ${last && showTotals(inv) ? `<table class="tot">${t.rows.map(([k, v]) => `<tr><td>${esc(k)}</td><td class="num">${v}</td></tr>`).join('')}<tr class="grand"><td>${esc(h.L.total)}</td><td class="num">${t.total}</td></tr></table>` : ''}
            ${last ? `<div class="notes">${notesHtml(inv)}</div>` : ''}
            ${inv.supplier.registry ? `<div class="legal">${esc(inv.supplier.registry)}</div>` : ''}
            ${pages.length > 1 ? `<div class="pageno">Página ${i + 1} de ${pages.length}</div>` : ''}
        </section>`;
    });

    return doc(css, html);
}

function showTotals(inv) {
    return inv.kind !== 'delivery_note' || inv.showPrices;
}

// ─── Moderna: sans, banda de color, totales a la derecha ─────────────────

function modern(inv, calc) {
    const h = helpers(inv);
    const pages = paginate(calc.lines, 15, 28, 6);
    const t = totals(inv, calc, h);
    const a = inv.style.accent;
    const css = `
        body { font-family: 'Inter', 'Liberation Sans', sans-serif; font-size: 9.5pt; color: #222; }
        .band { position: absolute; top: 0; left: 0; right: 0; height: 9mm; background: ${a}; }
        .head { display: flex; justify-content: space-between; margin-top: 8mm; }
        .brand { font-size: 17pt; font-weight: 700; color: ${a}; }
        .legalname { font-size: 8.5pt; color: #555; }
        .sinfo { font-size: 8.5pt; color: #555; margin-top: 4px; line-height: 1.45; }
        .title { font-size: 26pt; font-weight: 200; color: ${a}; text-align: right; letter-spacing: 1px; }
        .meta { margin-top: 6px; } .meta td { padding: 1px 0 1px 14px; text-align: right; }
        .meta td:first-child { color: #777; }
        .party { margin: 18px 0; padding: 10px 12px; background: #f4f6f8; border-left: 3px solid ${a}; width: 55%; line-height: 1.45; }
        .ph { font-size: 7.5pt; font-weight: 600; text-transform: uppercase; color: ${a}; letter-spacing: 1px; }
        .lines th { background: ${a}; color: #fff; font-weight: 600; padding: 6px 8px; text-align: left; font-size: 8.5pt; }
        .lines td { padding: 6px 8px; border-bottom: 1px solid #e3e6ea; }
        .lines th.num, .lines td.num { text-align: right; }
        .tot { width: 42%; margin: 14px 0 0 auto; } .tot td { padding: 4px 8px; }
        .tot tr.grand td { background: ${a}; color: #fff; font-weight: 700; font-size: 12pt; padding: 8px; }
        .notes { margin-top: 20px; font-size: 8.5pt; color: #555; } .notes p { margin: 2px 0; }
    `;

    const html = pages.map((lines, i) => {
        const last = i === pages.length - 1;
        return `<section class="page"><div class="band"></div>
            <div class="head"><div>${supplierHeader(inv, h)}</div><div><div class="title">${title(inv).toLowerCase().replace(/^./, (c) => c.toUpperCase())}</div><table class="meta">${meta(inv, h).map(([k, v]) => `<tr><td>${esc(k)}</td><td><strong>${esc(v)}</strong></td></tr>`).join('')}</table></div></div>
            ${i === 0 ? partyBlock(inv, h, inv.customer, h.L.customer) : '<div style="height:14px"></div>'}
            ${lines.length ? linesTable(inv, h, lines) : ''}
            ${last && showTotals(inv) ? `<table class="tot">${t.rows.map(([k, v]) => `<tr><td>${esc(k)}</td><td class="num">${v}</td></tr>`).join('')}<tr class="grand"><td>${esc(h.L.total)}</td><td class="num">${t.total}</td></tr></table>` : ''}
            ${last ? `<div class="notes">${notesHtml(inv)}</div>` : ''}
            ${inv.supplier.registry ? `<div class="legal">${esc(inv.supplier.registry)}</div>` : ''}
            ${pages.length > 1 ? `<div class="pageno">${i + 1} / ${pages.length}</div>` : ''}
        </section>`;
    });

    return doc(css, html);
}

// ─── ERP: rejilla con casillas, referencias y cuadro de impuestos ────────

function erp(inv, calc) {
    const h = helpers(inv);
    const pages = paginate(calc.lines, 20, 34, 7);
    const t = totals(inv, calc, h);
    const css = `
        body { font-family: 'DejaVu Sans', 'Liberation Sans', sans-serif; font-size: 8.3pt; color: #000; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .box { border: 1px solid #000; padding: 6px 8px; line-height: 1.35; }
        .brand { font-size: 13pt; font-weight: bold; }
        .legalname { font-size: 8pt; }
        .sinfo { font-size: 8pt; }
        .title { font-size: 14pt; font-weight: bold; text-align: center; border: 1px solid #000; background: #e6e6e6; padding: 3px; margin: 8px 0 6px; }
        .fields { display: grid; grid-template-columns: repeat(4, 1fr); border: 1px solid #000; margin-bottom: 6px; }
        .fields div { border-right: 1px solid #000; padding: 3px 5px; } .fields div:last-child { border-right: 0; }
        .fields small { display: block; font-size: 6.5pt; color: #333; text-transform: uppercase; }
        .ph { font-size: 6.5pt; text-transform: uppercase; }
        .party { }
        .lines { border: 1px solid #000; }
        .lines th { background: #e6e6e6; border: 1px solid #000; padding: 3px 4px; font-size: 7.5pt; text-align: left; }
        .lines td { padding: 2px 4px; border-left: 1px solid #000; border-right: 1px solid #000; }
        .lines th.num, .lines td.num { text-align: right; }
        .foot { display: grid; grid-template-columns: 1.6fr 1fr; gap: 6px; margin-top: 8px; }
        .taxgrid th { background: #e6e6e6; border: 1px solid #000; padding: 3px 4px; font-size: 7.5pt; }
        .taxgrid td { border: 1px solid #000; padding: 3px 4px; }
        .tot td { border: 1px solid #000; padding: 3px 6px; } .tot tr.grand td { background: #e6e6e6; font-weight: bold; font-size: 11pt; }
        .notes { margin-top: 8px; font-size: 7.5pt; border: 1px solid #000; padding: 5px; } .notes p { margin: 1px 0; }
    `;

    const fields = (i) => {
        const cells = [[h.L.number, inv.number], [h.L.date, h.d(inv.issueDate)], [inv.dueDate ? h.L.due : 'Cód. cliente', inv.dueDate ? h.d(inv.dueDate) : String(4300000 + (inv.number.length * 37) % 9999)], ['Página', `${i + 1}/${pages.length}`]];
        return `<div class="fields">${cells.map(([k, v]) => `<div><small>${esc(k)}</small>${esc(v)}</div>`).join('')}</div>`;
    };

    const html = pages.map((lines, i) => {
        const last = i === pages.length - 1;
        const surchargeOrMulti = calc.breakdown.length > 1 || calc.breakdown.some((r) => r.surcharge);
        const totRows = surchargeOrMulti
            ? [[h.L.base, h.m(calc.base)], [`Total ${h.L.tax}`, h.m(calc.tax)], ...(calc.surcharge ? [['Total R.E.', h.m(calc.surcharge)]] : []), ...(calc.withholding ? [[`Ret. IRPF ${h.r(inv.withholdingRate)}`, h.m(-calc.withholding)]] : [])]
            : t.rows;
        return `<section class="page">
            <div class="grid"><div class="box">${supplierHeader(inv, h)}</div>${partyBlock(inv, h, inv.customer, h.L.customer).replace('class="party"', 'class="box party"')}</div>
            <div class="title">${title(inv)}</div>
            ${fields(i)}
            ${linesTable(inv, h, lines.length ? lines : [], { codes: lines.some((l) => l.code), showRate: calc.breakdown.length > 1 })}
            ${last && showTotals(inv) ? `<div class="foot"><div>${inv.globalDiscount ? `<p>${esc(inv.globalDiscountLabel)} ${inv.globalDiscount} % sobre ${h.m(calc.gross)}: ${h.m(-calc.breakdown.reduce((s, r) => s + r.discount, 0))}</p>` : ''}${taxGrid(inv, calc, h)}</div><table class="tot">${totRows.map(([k, v]) => `<tr><td>${esc(k)}</td><td class="num">${v}</td></tr>`).join('')}<tr class="grand"><td>${esc(h.L.total)}</td><td class="num">${t.total}</td></tr></table></div>` : ''}
            ${last ? `<div class="notes">${notesHtml(inv) || '&nbsp;'}</div>` : '<p style="text-align:right">Suma y sigue...</p>'}
            ${inv.supplier.registry ? `<div class="legal">${esc(inv.supplier.registry)}</div>` : ''}
        </section>`;
    });

    return doc(css, html);
}

// ─── Sencilla: factura de autónomo hecha con un procesador de textos ─────

function simple(inv, calc) {
    const h = helpers(inv);
    const t = totals(inv, calc, h);
    const s = inv.supplier;
    const css = `
        body { font-family: 'Carlito', 'Liberation Sans', sans-serif; font-size: 11pt; color: #000; }
        .page { padding: 22mm 22mm; }
        h1 { font-size: 16pt; margin: 0 0 18px; }
        .two { display: flex; justify-content: space-between; margin-bottom: 22px; line-height: 1.4; }
        .lines th { text-align: left; border-bottom: 1px solid #000; padding: 4px; }
        .lines td { padding: 6px 4px; vertical-align: top; }
        .lines th.num, .lines td.num { text-align: right; }
        .tot { width: 55%; margin: 24px 0 0 auto; } .tot td { padding: 3px 4px; }
        .tot tr.grand td { border-top: 1px solid #000; font-weight: bold; }
        .notes { margin-top: 30px; font-size: 10pt; } .notes p { margin: 3px 0; }
    `;
    const html = `<section class="page">
        <h1>${title(inv)} ${inv.lang === 'es' ? 'Nº' : ''} ${esc(inv.number)}</h1>
        <div class="two">
            <div><strong>${esc(s.name)}</strong><br>${address(s)}<br>${taxIdLine(inv, h, s)}${s.phone ? `<br>Tel. ${esc(s.phone)}` : ''}</div>
            <div style="text-align:right">${esc(h.L.date)}: ${esc(h.d(inv.issueDate))}${inv.dueDate ? `<br>${esc(h.L.due)}: ${esc(h.d(inv.dueDate))}` : ''}</div>
        </div>
        <div class="two"><div><em>${esc(h.L.customer)}:</em><br><strong>${esc(inv.customer.name)}</strong><br>${address(inv.customer)}<br>${taxIdLine(inv, h, inv.customer)}</div></div>
        ${linesTable(inv, h, calc.lines)}
        <table class="tot">${t.rows.map(([k, v]) => `<tr><td>${esc(k)}</td><td class="num">${v}</td></tr>`).join('')}<tr class="grand"><td>${esc(h.L.total)}</td><td class="num">${t.total}</td></tr></table>
        <div class="notes">${notesHtml(inv)}</div>
    </section>`;
    return doc(css, [html]);
}

// ─── Suministros: resumen arriba, detalle por bloques ────────────────────

function utility(inv, calc) {
    const h = helpers(inv);
    const a = inv.style.accent;
    const u = inv.utility;
    const groups = [...new Set(calc.lines.map((l) => l.group))];
    const css = `
        body { font-family: 'Liberation Sans', 'DejaVu Sans', sans-serif; font-size: 8.8pt; color: #222; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; }
        .brand { font-size: 18pt; font-weight: bold; color: ${a}; }
        .legalname { font-size: 8pt; } .sinfo { font-size: 7.5pt; color: #555; line-height: 1.35; }
        .summary { display: grid; grid-template-columns: 1.2fr 1fr; gap: 10px; margin: 14px 0; }
        .card { border: 1px solid #ccd; border-radius: 6px; padding: 10px 12px; line-height: 1.5; }
        .big { background: ${a}; color: #fff; border: 0; }
        .big .amount { font-size: 24pt; font-weight: bold; }
        .k { color: #666; } .big .k { color: #eef; }
        h3 { font-size: 10pt; color: ${a}; border-bottom: 2px solid ${a}; padding-bottom: 3px; margin: 16px 0 6px; }
        .detail td { padding: 3px 4px; } .detail td.num { text-align: right; }
        .detail tr.group td { font-weight: bold; padding-top: 8px; }
        .detail tr.sum td { border-top: 1px solid #999; font-weight: bold; }
        .bars { display: flex; gap: 6px; align-items: flex-end; height: 70px; margin: 8px 0; }
        .bars div { flex: 1; background: #cfd8e3; } .bars div:last-child { background: ${a}; }
        .notes { font-size: 7.5pt; color: #555; margin-top: 12px; } .notes p { margin: 2px 0; }
    `;
    const vatRows = calc.breakdown.map((r) => `<tr><td>${r.rate === 0 ? 'Conceptos no sujetos a IVA' : `IVA ${h.r(r.rate)} s/ ${h.m(r.base)}`}</td><td class="num">${h.m(r.tax)}</td></tr>`).join('');
    const detail = groups.map((g) => `<tr class="group"><td colspan="2">${esc(g)}</td></tr>${calc.lines.filter((l) => l.group === g).map((l) => `<tr><td>${esc(l.description)}</td><td class="num">${h.m(l.amount)}</td></tr>`).join('')}`).join('');
    const bars = Array.from({ length: 12 }, (_, i) => `<div style="height:${25 + ((i * 37 + inv.number.length * 11) % 70)}%"></div>`).join('');

    const html = `<section class="page">
        <div class="head"><div>${supplierHeader(inv, h)}</div><div style="text-align:right"><strong>${title(inv)}</strong><br><span class="k">${esc(h.L.number)}</span> ${esc(inv.number)}<br><span class="k">${esc(h.L.date)}</span> ${esc(h.d(inv.issueDate))}</div></div>
        <div class="summary">
            <div class="card big"><div class="k">Importe total de la factura</div><div class="amount">${h.m(calc.printedTotal)}</div><div>Periodo de facturación: ${esc(h.d(inv.period[0]))} - ${esc(h.d(inv.period[1]))}</div><div>Se cargará en su cuenta el ${esc(h.d(inv.dueDate))}</div></div>
            <div class="card"><div class="k">Titular del contrato</div><strong>${esc(inv.customer.name)}</strong><br>${taxIdLine(inv, h, inv.customer)}<br>${address(inv.customer)}<br><span class="k">Nº contrato</span> ${esc(u.contract)}${u.cups ? `<br><span class="k">CUPS</span> ${esc(u.cups)}` : ''}</div>
        </div>
        <h3>Su consumo</h3>
        <div>Consumo del periodo: <strong>${esc(u.consumption)}</strong>. Evolución de los últimos 12 meses:</div>
        <div class="bars">${bars}</div>
        <h3>Detalle de la factura</h3>
        <table class="detail">${detail}
            <tr class="sum"><td>${esc(h.L.base)}</td><td class="num">${h.m(calc.base)}</td></tr>
            ${vatRows}
            <tr class="sum"><td>${esc(h.L.total)}</td><td class="num">${h.m(calc.printedTotal)}</td></tr>
        </table>
        <div class="notes">${notesHtml(inv)}<p>Puede consultar sus facturas y su consumo en el área de clientes. Atención al cliente 900 ${inv.number.length}00 ${inv.number.length * 7}0.</p></div>
        ${inv.supplier.registry ? `<div class="legal">${esc(inv.supplier.registry)}</div>` : ''}
    </section>`;
    return doc(css, [html]);
}

// ─── Ticket de TPV (80 mm) ────────────────────────────────────────────────

function ticket(inv, calc) {
    const h = helpers(inv);
    const s = inv.supplier;
    const letters = ['A', 'B', 'C', 'D'];
    const rateLetter = Object.fromEntries(calc.breakdown.map((r, i) => [r.rate, letters[i]]));
    const multi = calc.breakdown.length > 1;
    const css = `
        @page { size: 80mm 300mm; margin: 0; }
        body { font-family: 'DejaVu Sans Mono', 'Liberation Mono', monospace; font-size: 8.6pt; color: #111; }
        .page { width: 80mm; height: auto; min-height: 0; padding: 6mm 4mm 10mm; }
        .c { text-align: center; } .r { text-align: right; }
        hr { border: 0; border-top: 1px dashed #333; margin: 5px 0; }
        td { padding: 1px 0; vertical-align: top; } .big { font-size: 12pt; font-weight: bold; }
    `;
    const items = calc.lines.map((l) => {
        const q = l.qty === 1 ? '' : `<tr><td colspan="2">&nbsp; ${qty(h, l.qty)}${l.unitLabel ? ` ${esc(l.unitLabel)}` : ''} x ${unitPrice(h, l)}</td></tr>`;
        return `<tr><td>${esc(l.description)}${multi ? ` ${rateLetter[l.rate]}` : ''}</td><td class="r">${h.m(l.amount)}</td></tr>${q}`;
    }).join('');
    const taxes = calc.breakdown.map((r) => `<tr><td>${multi ? `${rateLetter[r.rate]} ` : ''}${h.r(r.rate)}</td><td class="r">${h.m(r.base)}</td><td class="r">${h.m(r.tax)}</td></tr>`).join('');

    const html = `<section class="page">
        <div class="c"><strong>${esc((s.commercialName ?? s.name).toUpperCase())}</strong><br>${esc(s.name)}<br>${esc(s.address.street)}<br>${esc(s.address.zip)} ${esc(s.address.city)}<br>${esc(h.L.taxId)}: ${esc(s.taxId)}</div>
        <hr><div class="c">${title(inv)}</div>
        <table><tr><td>Nº: ${esc(inv.number)}</td><td class="r">${esc(h.d(inv.issueDate))} ${esc(inv.time)}</td></tr></table>
        ${inv.ticketKind === 'gasolinera' ? `<div>SURTIDOR ${inv.number.length % 8 + 1}</div>` : ''}
        <hr><table>${items}</table><hr>
        <table><tr><td class="big">TOTAL</td><td class="r big">${h.m(calc.printedTotal)}</td></tr></table>
        <table><tr><td>${esc(inv.payment)}</td><td class="r">${h.m(calc.printedTotal)}</td></tr></table>
        <hr><table><tr><td>${multi ? 'TIPO' : 'IVA'}</td><td class="r">BASE</td><td class="r">CUOTA</td></tr>${taxes}</table>
        <hr><div class="c">IVA INCLUIDO<br>GRACIAS POR SU VISITA</div>
    </section>`;
    return doc(css, [html]);
}

// ─── Inglés / portugués: factura de SaaS o proveedor europeo ─────────────

function english(inv, calc) {
    const h = helpers(inv);
    const pt = inv.lang === 'pt';
    const L = pt
        ? { number: 'Fatura n.º', date: 'Data de emissão', due: 'Data de vencimento', billTo: 'Cliente', desc: 'Descrição', qty: 'Qtd.', price: 'Preço unit.', amount: 'Valor', subtotal: 'Total ilíquido', vat: 'IVA', total: 'Total a pagar' }
        : { number: 'Invoice number', date: 'Date of issue', due: 'Date due', billTo: 'Bill to', desc: 'Description', qty: 'Qty', price: 'Unit price', amount: 'Amount', subtotal: 'Subtotal', vat: 'VAT', total: 'Amount due' };
    const s = inv.supplier;
    const row = calc.breakdown[0];
    const css = `
        body { font-family: 'Inter', 'Liberation Sans', sans-serif; font-size: 9.5pt; color: #1a1a1a; }
        .page { padding: 18mm; }
        .top { display: flex; justify-content: space-between; }
        h1 { font-size: 20pt; margin: 0 0 10px; font-weight: 600; }
        .meta td { padding: 1px 16px 1px 0; } .meta td:first-child { color: #666; }
        .cols { display: flex; gap: 40px; margin: 22px 0; line-height: 1.5; }
        .k { color: #666; font-size: 8.5pt; }
        .due { font-size: 15pt; font-weight: 600; margin: 4px 0 18px; }
        .lines th { text-align: left; color: #666; font-weight: 500; border-bottom: 1px solid #ddd; padding: 6px 4px; }
        .lines td { padding: 8px 4px; border-bottom: 1px solid #eee; }
        .lines th.num, .lines td.num { text-align: right; }
        .tot { width: 50%; margin: 10px 0 0 auto; } .tot td { padding: 4px; } .tot tr.grand td { font-weight: 600; border-top: 1px solid #ddd; }
        .notes { margin-top: 28px; font-size: 8.5pt; color: #444; } .notes p { margin: 2px 0; }
    `;
    const lines = calc.lines.map((l) => `<tr><td>${esc(l.description)}${inv.period ? '' : `<br><span class="k">${esc(h.d(inv.issueDate))}</span>`}</td><td class="num">${qty(h, l.qty)}</td><td class="num">${h.m(l.unit)}</td><td class="num">${h.m(l.amount)}</td></tr>`).join('');

    const html = `<section class="page">
        <div class="top"><div><h1>${title(inv) === 'INVOICE' ? 'Invoice' : title(inv)}</h1><table class="meta"><tr><td>${L.number}</td><td>${esc(inv.number)}</td></tr><tr><td>${L.date}</td><td>${esc(h.d(inv.issueDate))}</td></tr>${inv.dueDate ? `<tr><td>${L.due}</td><td>${esc(h.d(inv.dueDate))}</td></tr>` : ''}</table></div><div style="font-size:16pt;font-weight:700">${esc(s.name.split(' ')[0])}</div></div>
        <div class="cols">
            <div><strong>${esc(s.name)}</strong><br>${esc(s.address.street)}<br>${esc([s.address.zip, s.address.city].filter(Boolean).join(' '))}<br>${s.email ? `${esc(s.email)}<br>` : ''}${inv.lang === 'pt' ? 'NIF' : 'VAT'}: ${esc(s.taxId)}</div>
            <div><span class="k">${L.billTo}</span><br><strong>${esc(inv.customer.name)}</strong><br>${address(inv.customer)}<br>VAT: ${esc(inv.customer.taxId)}</div>
        </div>
        <div class="due">${h.m(calc.printedTotal)} ${inv.currency !== 'EUR' ? inv.currency : ''} ${pt ? 'a pagar' : 'due'}${inv.dueDate ? ` ${esc(h.d(inv.dueDate))}` : ''}</div>
        <table class="lines"><thead><tr><th>${L.desc}</th><th class="num">${L.qty}</th><th class="num">${L.price}</th><th class="num">${L.amount}</th></tr></thead><tbody>${lines}</tbody></table>
        <table class="tot"><tr><td>${L.subtotal}</td><td class="num">${h.m(calc.base)}</td></tr><tr><td>${L.vat} (${h.r(row.rate)})</td><td class="num">${h.m(calc.tax)}</td></tr><tr><td>Total</td><td class="num">${h.m(calc.printedTotal)}</td></tr><tr class="grand"><td>${L.total}</td><td class="num">${h.m(calc.printedTotal)}</td></tr></table>
        <div class="notes">${notesHtml(inv)}</div>
    </section>`;
    return doc(css, [html]);
}

// ─── Documentos que no son facturas ──────────────────────────────────────

function contract(inv, calc) {
    const h = helpers(inv);
    const c = inv.customer;
    const s = inv.supplier;
    const fee = calc.lines[0]?.amount ?? 0;
    const css = `body { font-family: 'Liberation Serif', serif; font-size: 11pt; line-height: 1.55; text-align: justify; } .page { padding: 24mm; } h1 { text-align: center; font-size: 14pt; } h2 { font-size: 11pt; margin: 14px 0 4px; }`;
    const html = `<section class="page">
        <h1>CONTRATO DE PRESTACIÓN DE SERVICIOS</h1>
        <p>En ${esc(s.address.city)}, a ${esc(formatDate(inv.issueDate, 'long'))}.</p>
        <h2>REUNIDOS</h2>
        <p>De una parte, ${esc(s.name)}, con ${esc(h.L.taxId)} ${esc(s.taxId)} y domicilio en ${esc(s.address.street)}, ${esc(s.address.city)} (en adelante, el PRESTADOR).</p>
        <p>De otra parte, ${esc(c.name)}, con ${esc(h.L.taxId)} ${esc(c.taxId)} y domicilio en ${esc(c.address.street)}, ${esc(c.address.city)} (en adelante, el CLIENTE).</p>
        <h2>CLÁUSULAS</h2>
        <p><strong>Primera. Objeto.</strong> El PRESTADOR se compromete a realizar los siguientes servicios: ${esc(calc.lines.map((l) => l.description.toLowerCase()).join('; '))}.</p>
        <p><strong>Segunda. Precio.</strong> El precio de los servicios es de ${h.m(fee)} mensuales, más el IVA correspondiente, pagaderos por transferencia bancaria en los diez primeros días de cada mes. El PRESTADOR emitirá la factura correspondiente a cada mensualidad.</p>
        <p><strong>Tercera. Duración.</strong> El contrato tendrá una duración de un año desde su firma y se prorrogará tácitamente por periodos iguales salvo preaviso de treinta días.</p>
        <p><strong>Cuarta. Confidencialidad y protección de datos.</strong> Ambas partes se obligan a guardar la confidencialidad de la información a la que tengan acceso y a cumplir la normativa de protección de datos.</p>
        <p><strong>Quinta. Jurisdicción.</strong> Para cualquier controversia, las partes se someten a los juzgados y tribunales de ${esc(s.address.city)}.</p>
        <p style="margin-top:40px; display:flex; justify-content:space-between"><span>EL PRESTADOR</span><span>EL CLIENTE</span></p>
    </section>`;
    return doc(css, [html]);
}

function payslip(inv) {
    const h = helpers({ ...inv, style: { ...inv.style, money: 'es-nosymbol' } });
    const e = inv.employee;
    const gross = inv.salary;
    const ss = Math.round(gross * 0.0647);
    const irpf = Math.round(gross * 0.12);
    const css = `body { font-family: 'Liberation Sans', sans-serif; font-size: 8.5pt; } .box { border: 1px solid #000; padding: 6px; margin-bottom: 6px; } table td, table th { border: 1px solid #000; padding: 3px 5px; } th { background: #eee; } .num { text-align: right; } h1 { font-size: 12pt; text-align: center; }`;
    const html = `<section class="page">
        <h1>RECIBO INDIVIDUAL JUSTIFICATIVO DEL PAGO DE SALARIOS</h1>
        <div class="box"><strong>Empresa:</strong> ${esc(inv.supplier.name)} · ${esc(h.L.taxId)} ${esc(inv.supplier.taxId)} · CCC 28/${inv.supplier.taxId.slice(1, 8)}<br>${address(inv.supplier)}</div>
        <div class="box"><strong>Trabajador:</strong> ${esc(e.name)} · NIF ${esc(e.nif)} · Nº afiliación S.S. ${esc(e.nss)} · Categoría: ${esc(e.category)}</div>
        <div class="box">Periodo de liquidación: del 1 al 30 de ${esc(formatDate(inv.issueDate, 'long').split(' de ').slice(1).join(' de '))} · Total días: 30</div>
        <table><tr><th>I. DEVENGOS</th><th class="num">Importe</th></tr>
            <tr><td>Salario base</td><td class="num">${h.m(Math.round(gross * 0.8))}</td></tr>
            <tr><td>Plus convenio</td><td class="num">${h.m(gross - Math.round(gross * 0.8))}</td></tr>
            <tr><th>A. TOTAL DEVENGADO</th><th class="num">${h.m(gross)}</th></tr>
            <tr><th>II. DEDUCCIONES</th><th></th></tr>
            <tr><td>Contingencias comunes, desempleo y formación (6,47 %)</td><td class="num">${h.m(ss)}</td></tr>
            <tr><td>Retención IRPF (12,00 %)</td><td class="num">${h.m(irpf)}</td></tr>
            <tr><th>B. TOTAL A DEDUCIR</th><th class="num">${h.m(ss + irpf)}</th></tr>
            <tr><th>LÍQUIDO TOTAL A PERCIBIR (A - B)</th><th class="num">${h.m(gross - ss - irpf)}</th></tr>
        </table>
        <p style="margin-top:30px">Firma y sello de la empresa &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Recibí</p>
    </section>`;
    return doc(css, [html]);
}

function flyer(inv, calc) {
    const h = helpers({ ...inv, style: { ...inv.style, money: 'es-symbol' } });
    const a = inv.style.accent;
    const css = `body { font-family: 'Inter', sans-serif; } .page { background: linear-gradient(160deg, ${a}, #111); color: #fff; padding: 20mm; } h1 { font-size: 40pt; margin: 0; } .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 20mm; } .item { background: rgba(255,255,255,.1); border-radius: 10px; padding: 14px; } .price { font-size: 22pt; font-weight: 800; color: #ffd54f; } .old { text-decoration: line-through; opacity: .7; }`;
    const html = `<section class="page">
        <div style="opacity:.8">${esc(inv.supplier.name)}</div>
        <h1>¡OFERTAS DE TEMPORADA!</h1>
        <div style="font-size:14pt">Solo hasta el ${esc(formatDate(inv.issueDate, 'long'))}. Pedido mínimo 150 €.</div>
        <div class="grid">${calc.lines.slice(0, 6).map((l) => `<div class="item"><div>${esc(l.description)}</div><div class="old">${h.m(Math.round(l.unit * 1.25))}</div><div class="price">${h.m(Math.round(l.unit))}</div><small>IVA no incluido</small></div>`).join('')}</div>
        <p style="margin-top:20mm">Llámenos al ${esc(inv.supplier.phone)} o escríbanos a ${esc(inv.supplier.email)}. Para darse de baja de estas comunicaciones responda a este correo.</p>
    </section>`;
    return doc(css, [html]);
}

const TEMPLATES = { classic, modern, erp, simple, utility, ticket, english, contract, payslip, flyer };

export function render(inv, calc) {
    const template = TEMPLATES[inv.template];
    if (!template) {
        throw new Error(`Plantilla desconocida: ${inv.template}`);
    }
    return template(inv, calc);
}
