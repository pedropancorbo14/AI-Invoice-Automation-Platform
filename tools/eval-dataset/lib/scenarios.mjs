// Escenarios: cada función devuelve una factura (o un documento que no lo es)
// lista para pintar. Imitan lo que llega de verdad a una gestoría: servicios,
// mayoristas, autónomos con IRPF, suministros, tickets, rectificativas,
// facturas extranjeras, facturas emitidas, errores del proveedor y papeles
// que no son facturas.

import {
    makeBadCif, makeCompany, makeIban, makePerson, makePersonName,
    PROFESSIONAL_SERVICES, SERVICE_SUPPLIERS, SURCHARGE, WHOLESALERS,
} from './data.mjs';
import { addDays, monthName, round } from './money.mjs';

const ES_MONEY = ['es-symbol', 'es-symbol', 'es-tight', 'es-nosymbol', 'es-plain', 'es-prefix'];
const ES_DATES = ['slash', 'slash', 'slash', 'dash', 'long', 'month-short', 'short-year', 'dot'];

/** Etiquetas que cambian de un programa de facturación a otro. */
export function labels(rnd) {
    return {
        number: rnd.pick(['Factura nº', 'Nº Factura', 'Número', 'Factura', 'Nº de factura', 'Num. Factura']),
        date: rnd.pick(['Fecha', 'Fecha factura', 'Fecha de emisión', 'Fecha expedición', 'F. Factura']),
        due: rnd.pick(['Vencimiento', 'Fecha de vencimiento', 'Vto.', 'Fecha de pago']),
        taxId: rnd.pick(['CIF', 'NIF', 'C.I.F.', 'N.I.F.', 'CIF/NIF']),
        customer: rnd.pick(['Cliente', 'Facturar a', 'Datos del cliente', 'Destinatario', 'CLIENTE']),
        base: rnd.pick(['Base imponible', 'Subtotal', 'B. Imponible', 'Base', 'Importe neto']),
        tax: rnd.pick(['IVA', 'Cuota IVA', 'I.V.A.', 'Cuota']),
        total: rnd.pick(['Total factura', 'TOTAL', 'Total a pagar', 'Importe total', 'TOTAL FACTURA', 'Total (EUR)']),
        description: rnd.pick(['Descripción', 'Concepto', 'Artículo', 'Detalle']),
        qty: rnd.pick(['Cant.', 'Cantidad', 'Uds.', 'Unid.']),
        price: rnd.pick(['Precio', 'Precio ud.', 'P. Unit.', 'Importe ud.']),
        amount: rnd.pick(['Importe', 'Total', 'Subtotal']),
    };
}

function style(rnd, overrides = {}) {
    return { money: rnd.pick(ES_MONEY), date: rnd.pick(ES_DATES), labels: labels(rnd), accent: rnd.pick(ACCENTS), ...overrides };
}

const ACCENTS = ['#1f4e79', '#8b1e3f', '#2e7d32', '#e65100', '#37474f', '#5e35b1', '#00695c', '#b71c1c', '#283593'];

/** Fecha de emisión dentro de 2026 (la evaluación no mira la antigüedad). */
function issueDate(rnd) {
    const start = Date.UTC(2026, 0, 8);
    const end = Date.UTC(2026, 10, 14);
    return new Date(start + rnd.next() * (end - start)).toISOString().slice(0, 10);
}

function invoiceNumber(rnd, date) {
    const year = date.slice(0, 4);
    const n = rnd.int(1, 2400);
    return rnd.pick([
        `F-${year}-${String(n).padStart(4, '0')}`,
        `${year}/${String(n).padStart(5, '0')}`,
        `FV${year.slice(2)}-${String(n).padStart(6, '0')}`,
        `A${String(n).padStart(6, '0')}`,
        `${String(n).padStart(3, '0')}/${year.slice(2)}`,
        `FAC-${rnd.digits(7)}`,
        `${year}-${rnd.pick(['A', 'B', 'V'])}${String(n).padStart(4, '0')}`,
    ]);
}

function dueDate(rnd, date) {
    return rnd.chance(0.55) ? addDays(date, rnd.pick([0, 15, 30, 30, 30, 45, 60])) : null;
}

function payment(rnd, supplier, due) {
    return rnd.pick([
        `Transferencia bancaria a ${supplier.iban}`,
        `Forma de pago: recibo domiciliado${due ? '' : ' a 30 días'}`,
        `Pago por transferencia. IBAN: ${supplier.iban}`,
        'Forma de pago: contado',
        `Forma de pago: confirming ${rnd.pick([30, 60])} días`,
    ]);
}

function money(rnd, [min, max]) {
    return round(rnd.int(min, max) / 5) * 5;
}

function base(rnd, client, kind = 'invoice') {
    const date = issueDate(rnd);
    return {
        kind,
        lang: 'es',
        client,
        currency: 'EUR',
        issueDate: date,
        number: invoiceNumber(rnd, date),
        dueDate: null,
        customer: client,
        style: style(rnd),
        tags: [],
        notes: [],
        lines: [],
    };
}

// ─── Facturas recibidas ──────────────────────────────────────────────────

/** Servicio habitual al 21 %: limpieza, mantenimiento, web, transporte... */
export function serviceInvoice(rnd, client) {
    const spec = rnd.pick(SERVICE_SUPPLIERS);
    const supplier = makeCompany(rnd, spec.base, { commercial: spec.commercial });
    const inv = base(rnd, client);
    const items = rnd.sample(spec.items, rnd.int(1, Math.min(3, spec.items.length)));

    inv.supplier = supplier;
    inv.dueDate = dueDate(rnd, inv.issueDate);
    inv.template = rnd.pick(['classic', 'modern', 'erp', 'modern']);
    inv.lines = items.map((description, i) => ({
        description: i === 0 && rnd.chance(0.4) ? `${description} - ${monthName(inv.issueDate)} ${inv.issueDate.slice(0, 4)}` : description,
        qty: rnd.chance(0.7) ? 1 : rnd.int(2, 6),
        unit: i === 0 ? money(rnd, spec.range) : round(money(rnd, spec.range) / 2),
        rate: 21,
    }));
    inv.payment = payment(rnd, supplier, inv.dueDate);
    inv.tags.push('servicios');

    return inv;
}

/** Mayorista del sector: muchas líneas, tipos de IVA mezclados y descuentos. */
export function goodsInvoice(rnd, client, { lines: count } = {}) {
    const catalog = WHOLESALERS[client.sector];
    const supplier = makeCompany(rnd, rnd.pick(catalog.names));
    const inv = base(rnd, client);
    const n = count ?? rnd.int(3, 12);

    inv.supplier = supplier;
    inv.dueDate = dueDate(rnd, inv.issueDate);
    inv.template = rnd.pick(['erp', 'erp', 'classic', 'modern']);
    inv.lines = Array.from({ length: n }, (_, i) => {
        const [description, unit, rate, unitLabel] = catalog.items[i % catalog.items.length];
        const weighed = unitLabel === 'kg';
        return {
            code: `${rnd.pick(['A', 'R', 'P', ''])}${rnd.digits(5)}`,
            description,
            qty: weighed ? Math.round(rnd.float(1.5, 18) * 100) / 100 : rnd.int(1, 24),
            unit: Math.max(50, unit + rnd.int(-30, 30)),
            unitLabel,
            discount: rnd.chance(0.2) ? rnd.pick([5, 10, 15]) : 0,
            rate,
        };
    });

    if (rnd.chance(0.25)) {
        inv.globalDiscount = rnd.pick([2, 3, 5]);
        inv.globalDiscountLabel = rnd.pick(['Dto. pronto pago', 'Descuento comercial', 'Dto. cliente']);
        inv.tags.push('descuento-global');
    }

    const rates = new Set(inv.lines.map((line) => line.rate));
    if (rates.size > 1) {
        inv.tags.push('varios-tipos-iva');
    }
    if (inv.lines.some((line) => line.discount)) {
        inv.tags.push('descuento-linea');
    }
    if (n > 22) {
        inv.tags.push('varias-paginas');
    }
    inv.payment = payment(rnd, supplier, inv.dueDate);
    inv.tags.push('mercaderia');

    return inv;
}

/** Comercio en recargo de equivalencia: cada tipo de IVA lleva su recargo. */
export function surchargeInvoice(rnd, client) {
    const inv = goodsInvoice(rnd, client, { lines: rnd.int(4, 14) });
    inv.surcharge = true;
    inv.surchargeRates = SURCHARGE;
    inv.template = rnd.pick(['erp', 'erp', 'classic']);
    inv.tags = inv.tags.filter((tag) => tag !== 'mercaderia');
    inv.tags.push('recargo-equivalencia');
    inv.notes.push('Cliente acogido al régimen especial del recargo de equivalencia.');

    return inv;
}

/** Profesional autónomo: 21 % de IVA y retención de IRPF del 15 % (o 7 %). */
export function professionalInvoice(rnd, client) {
    const person = makePerson(rnd, { nie: rnd.chance(0.15) });
    const inv = base(rnd, client);
    const services = rnd.pick(PROFESSIONAL_SERVICES);
    const firstYears = rnd.chance(0.2);

    inv.supplier = person;
    inv.template = rnd.pick(['simple', 'simple', 'classic', 'modern']);
    inv.number = rnd.pick([`${inv.issueDate.slice(0, 4)}-${String(rnd.int(1, 90)).padStart(3, '0')}`, `${rnd.int(1, 60)}/${inv.issueDate.slice(0, 4)}`, String(rnd.int(10, 99))]);
    inv.dueDate = rnd.chance(0.3) ? addDays(inv.issueDate, 30) : null;
    inv.lines = rnd.sample(services, rnd.int(1, 2)).map((description) => ({
        description,
        qty: rnd.chance(0.8) ? 1 : rnd.int(2, 10),
        unit: rnd.chance(0.3) ? round(money(rnd, [8000, 120000]) / 4) : money(rnd, [8000, 120000]),
        rate: 21,
    }));
    inv.withholdingRate = firstYears ? 7 : 15;
    inv.payment = `Transferencia a ${person.iban}`;
    inv.tags.push('autonomo', 'irpf');

    return inv;
}

/** Alquiler de local: arrendador persona física, 21 % de IVA y 19 % de retención. */
export function rentInvoice(rnd, client) {
    const landlord = makePerson(rnd);
    const inv = base(rnd, client);
    const rent = money(rnd, [45000, 180000]);

    inv.supplier = landlord;
    inv.template = 'simple';
    inv.number = `${inv.issueDate.slice(0, 4)}/${String(Number(inv.issueDate.slice(5, 7))).padStart(2, '0')}`;
    inv.dueDate = addDays(inv.issueDate, rnd.pick([0, 5, 10]));
    inv.lines = [{ description: `Renta del local comercial sito en ${client.address.street}, ${client.address.city}. Mes de ${monthName(inv.issueDate)} de ${inv.issueDate.slice(0, 4)}`, qty: 1, unit: rent, rate: 21 }];
    inv.withholdingRate = 19;
    inv.payment = `Domiciliación bancaria. IBAN del arrendador: ${landlord.iban}`;
    inv.tags.push('alquiler', 'irpf');

    return inv;
}

/** Factura de luz: muchos conceptos, impuesto eléctrico y el IVA al final. */
export function electricityBill(rnd, client) {
    const supplier = makeCompany(rnd, rnd.pick(['Energía Verde Peninsular', 'Comercializadora Eléctrica Solaris', 'Luz y Gas del Norte']), { legalForm: 'S.A.' });
    const inv = base(rnd, client);
    const days = rnd.int(28, 33);
    const power = rnd.pick([3.45, 4.6, 5.75, 6.9, 9.2, 15]);
    const kwh = rnd.int(180, 2400);
    const energyPrice = rnd.float(0.11, 0.19);
    const powerCost = round(power * days * 10.4229);
    const energyCost = round(kwh * energyPrice * 100);
    const tax = round((powerCost + energyCost) * 0.0511269632);
    const meter = round(days * 2.663);
    const start = addDays(inv.issueDate, -days - rnd.int(2, 6));

    inv.supplier = supplier;
    inv.template = 'utility';
    inv.number = `${rnd.pick(['FE', 'PE', 'EL'])}${inv.issueDate.slice(2, 4)}${rnd.digits(9)}`;
    inv.dueDate = addDays(inv.issueDate, rnd.pick([7, 10, 15]));
    inv.period = [start, addDays(start, days)];
    inv.utility = { kind: 'luz', cups: `ES0031${rnd.digits(12)}${rnd.pick(['JN', 'QX', 'FA'])}0F`, contract: rnd.digits(10), consumption: `${kwh} kWh` };
    inv.lines = [
        { description: `Término de potencia: ${String(power).replace('.', ',')} kW x ${days} días x 0,104229 €/kW día`, amount: powerCost, rate: 21, group: 'Energía' },
        { description: `Energía consumida: ${kwh} kWh x ${energyPrice.toFixed(6).replace('.', ',')} €/kWh`, amount: energyCost, rate: 21, group: 'Energía' },
        { description: 'Impuesto sobre la electricidad 5,11269632 %', amount: tax, rate: 21, group: 'Impuestos' },
        { description: `Alquiler de equipos de medida: ${days} días x 0,026630 €/día`, amount: meter, rate: 21, group: 'Otros' },
    ];
    inv.payment = `Recibo domiciliado en ${makeIban(rnd).slice(0, 9)} **** **** ${rnd.digits(4)}`;
    inv.tags.push('suministro', 'luz');

    return inv;
}

/** Fibra y móvil: cuotas, una línea extra y una promoción en negativo. */
export function telecomBill(rnd, client) {
    const supplier = makeCompany(rnd, rnd.pick(['Telecomunicaciones Onda Fija', 'Redes y Fibra Comarcal']), { legalForm: 'S.A.' });
    const inv = base(rnd, client);
    const start = addDays(inv.issueDate, -30);

    inv.supplier = supplier;
    inv.template = 'utility';
    inv.number = `${rnd.pick(['TF', 'MV'])}-${rnd.digits(4)}-${rnd.digits(6)}`;
    inv.dueDate = addDays(inv.issueDate, 10);
    inv.period = [start, addDays(start, 30)];
    inv.utility = { kind: 'telefonia', contract: rnd.digits(9), consumption: `${rnd.int(2, 40)} GB` };
    inv.lines = [
        { description: 'Cuota Fibra 1 Gb + Fijo', amount: money(rnd, [3500, 5500]), rate: 21, group: 'Cuotas' },
        { description: `Línea móvil 6${rnd.digits(2)} ${rnd.digits(3)} ${rnd.digits(3)} (datos ilimitados)`, amount: money(rnd, [1500, 3000]), rate: 21, group: 'Cuotas' },
        { description: 'Llamadas a números especiales 902', amount: rnd.int(30, 900), rate: 21, group: 'Consumos' },
        { description: 'Descuento promoción empresa (12 meses)', amount: -money(rnd, [500, 1500]), rate: 21, group: 'Descuentos' },
    ];
    inv.payment = 'Cargo en cuenta domiciliada';
    inv.tags.push('suministro', 'telefonia', 'linea-negativa');

    return inv;
}

/** Agua al 10 % con una tasa municipal no sujeta a IVA (tipo 0). */
export function waterBill(rnd, client) {
    const supplier = makeCompany(rnd, rnd.pick(['Aguas Municipales del Campo', 'Gestión Integral del Agua Ribera']), { legalForm: 'S.A.' });
    const inv = base(rnd, client);
    const m3 = rnd.int(8, 95);
    const start = addDays(inv.issueDate, -60);

    inv.supplier = supplier;
    inv.template = 'utility';
    inv.number = `${rnd.digits(2)}/${rnd.digits(8)}`;
    inv.dueDate = addDays(inv.issueDate, 20);
    inv.period = [start, addDays(start, 60)];
    inv.utility = { kind: 'agua', contract: rnd.digits(8), consumption: `${m3} m³` };
    inv.lines = [
        { description: 'Cuota de servicio abastecimiento (bimestral)', amount: rnd.int(900, 2200), rate: 10, group: 'Abastecimiento' },
        { description: `Consumo: ${m3} m³ x 0,8412 €/m³`, amount: round(m3 * 84.12), rate: 10, group: 'Abastecimiento' },
        { description: 'Cuota de alcantarillado', amount: round(m3 * rnd.float(20, 45)), rate: 10, group: 'Saneamiento' },
        { description: 'Tasa de recogida de residuos (no sujeta a IVA)', amount: rnd.int(1500, 4800), rate: 0, group: 'Tasas municipales' },
    ];
    inv.payment = 'Cargo en cuenta domiciliada';
    inv.tags.push('suministro', 'agua', 'varios-tipos-iva', 'iva-0');

    return inv;
}

/** Formación bonificada: exenta de IVA (art. 20 LIVA). */
export function exemptInvoice(rnd, client) {
    const supplier = makeCompany(rnd, rnd.pick(['Centro de Formación Avanza', 'Academia Profesional Quórum']));
    const inv = base(rnd, client);

    inv.supplier = supplier;
    inv.template = rnd.pick(['classic', 'modern']);
    inv.dueDate = dueDate(rnd, inv.issueDate);
    inv.lines = [{ description: rnd.pick(['Curso de manipulador de alimentos (8 alumnos)', 'Curso de Excel avanzado (20 h)', 'Curso de prevención de riesgos laborales (60 h)']), qty: 1, unit: money(rnd, [18000, 90000]), rate: 0 }];
    inv.notes.push('Operación exenta de IVA según el artículo 20.Uno.9º de la Ley 37/1992.');
    inv.tags.push('exenta', 'iva-0');

    return inv;
}

/** Factura rectificativa por devolución: importes en negativo. */
export function creditNote(rnd, client) {
    const inv = rnd.chance(0.5) ? goodsInvoice(rnd, client, { lines: rnd.int(1, 3) }) : serviceInvoice(rnd, client);
    const original = invoiceNumber(rnd, inv.issueDate);

    inv.kind = 'credit_note';
    inv.dueDate = null;
    inv.globalDiscount = 0;
    inv.lines = inv.lines.map((line) => ({ ...line, qty: -Math.abs(line.qty) }));
    inv.number = `R-${inv.number}`;
    inv.rectifies = { number: original, date: addDays(inv.issueDate, -rnd.int(5, 40)), reason: rnd.pick(['Devolución de mercancía en mal estado', 'Error en el precio facturado', 'Anulación del servicio no prestado']) };
    inv.tags = ['rectificativa'];

    return inv;
}

/** Ticket de TPV: factura simplificada con IVA incluido y sin datos del cliente. */
export function ticket(rnd, client, kind = rnd.pick(['gasolinera', 'bar', 'supermercado', 'parking', 'ferreteria'])) {
    const names = {
        gasolinera: ['Estaciones de Servicio La Vega', 'E.S. Autovía Sur'],
        bar: ['Cafetería Los Arcos', 'Bar Restaurante El Cruce'],
        supermercado: ['Supermercados Despensa Familiar', 'Autoservicio La Plaza'],
        parking: ['Aparcamientos Centro Ciudad', 'Parking Estación Norte'],
        ferreteria: ['Ferretería Industrial El Clavo', 'Bricolaje y Ferretería Torres'],
    };
    const supplier = makeCompany(rnd, rnd.pick(names[kind]));
    const inv = base(rnd, null, 'simplified_invoice');

    inv.client = client;
    inv.customer = null;
    inv.supplier = supplier;
    inv.template = 'ticket';
    inv.pricesIncludeTax = true;
    inv.time = `${String(rnd.int(7, 21)).padStart(2, '0')}:${String(rnd.int(0, 59)).padStart(2, '0')}`;
    inv.number = rnd.pick([`T${rnd.digits(3)}-${rnd.digits(6)}`, `FS${inv.issueDate.slice(2, 4)}/${rnd.digits(6)}`, `${rnd.digits(4)}/${rnd.digits(5)}`]);
    inv.style.date = rnd.pick(['slash', 'short-year', 'dash']);
    inv.style.money = rnd.pick(['es-nosymbol', 'es-plain', 'es-symbol']);

    const lines = {
        gasolinera: () => {
            const litres = Math.round(rnd.float(20, 65) * 100) / 100;
            return [{ description: rnd.pick(['GASOLEO A', 'SIN PLOMO 95', 'DIESEL E+']), qty: litres, unit: rnd.int(1390, 1690) / 10, unitDecimals: 3, rate: 21, unitLabel: 'L' }];
        },
        bar: () => rnd.sample([
            ['MENU DEL DIA', 1350], ['CAFE CON LECHE', 160], ['CAÑA', 220], ['AGUA 50CL', 150], ['TOSTADA TOMATE', 280], ['RACION CALAMARES', 1250], ['REFRESCO', 250],
        ], rnd.int(2, 5)).map(([description, unit]) => ({ description, qty: rnd.int(1, 3), unit, rate: 10 })),
        supermercado: () => rnd.sample([
            ['LECHE ENTERA 1L', 95, 4], ['PAN BARRA', 65, 4], ['HUEVOS L 12U', 285, 4], ['ACEITE GIRASOL 1L', 189, 4], ['YOGUR NATURAL 4U', 135, 10],
            ['AGUA MINERAL 6X1,5L', 199, 10], ['JAMON COCIDO 200G', 249, 10], ['DETERGENTE 40 LAV', 799, 21], ['PAPEL COCINA 2R', 215, 21], ['LEJIA 2L', 129, 21],
        ], rnd.int(4, 8)).map(([description, unit, rate]) => ({ description, qty: rnd.int(1, 4), unit, rate })),
        parking: () => [{ description: `ESTANCIA ${rnd.int(1, 9)}h ${rnd.int(0, 59)}min`, qty: 1, unit: rnd.int(250, 2400), rate: 21 }],
        ferreteria: () => rnd.sample([
            ['TORNILLO 4X40 (100U)', 495], ['CINTA AMERICANA', 650], ['BROCA HSS 6MM', 320], ['SILICONA NEUTRA', 585], ['GUANTES TRABAJO', 390], ['PILAS AA 4U', 475],
        ], rnd.int(1, 4)).map(([description, unit]) => ({ description, qty: rnd.int(1, 3), unit, rate: 21 })),
    };

    inv.lines = lines[kind]();
    inv.ticketKind = kind;
    inv.payment = rnd.pick(['TARJETA', 'EFECTIVO', 'TARJETA CONTACTLESS']);
    inv.tags.push('ticket', `ticket-${kind}`);
    if (new Set(inv.lines.map((line) => line.rate)).size > 1) {
        inv.tags.push('varios-tipos-iva');
    }

    return inv;
}

/** Proveedor de otro país de la UE o de EE. UU.: en inglés, inversión del sujeto pasivo. */
export function foreignInvoice(rnd, client, origin = rnd.pick(['ie', 'us', 'de'])) {
    const inv = base(rnd, client);
    const specs = {
        ie: { name: 'Cloudnest Software Ltd', taxId: `IE${rnd.digits(7)}${rnd.pick(['T', 'W', 'H'])}`, items: ['Team plan – 5 seats', 'Additional storage 100 GB'], range: [4900, 39900], currency: 'EUR', city: 'Dublin, D02 X285, Ireland', street: '14 Harbour Road' },
        us: { name: 'Pixelforge Inc.', taxId: `EU372${rnd.digits(6)}`, items: ['Pro subscription (annual)', 'Render credits pack'], range: [9900, 119900], currency: 'USD', city: 'San Francisco, CA 94107, United States', street: '500 Howard Street' },
        de: { name: 'Messebau Krüger GmbH', taxId: `DE${rnd.digits(9)}`, items: ['Trade fair stand design', 'Stand assembly and dismantling', 'Furniture rental'], range: [45000, 380000], currency: 'EUR', city: '10115 Berlin, Germany', street: 'Invalidenstraße 112' },
    };
    const spec = specs[origin];

    inv.lang = 'en';
    inv.currency = spec.currency;
    inv.supplier = { name: spec.name, taxId: spec.taxId, address: { street: spec.street, zip: '', city: spec.city, province: '' }, email: `billing@${spec.name.split(' ')[0].toLowerCase()}.com`, phone: null, iban: null };
    inv.customer = { ...client, taxId: `ES${client.taxId}` };
    inv.template = 'english';
    inv.number = rnd.pick([`INV-${rnd.digits(6)}`, `${rnd.pick(['CN', 'PF', 'MK'])}-${inv.issueDate.slice(0, 4)}-${rnd.digits(4)}`]);
    inv.dueDate = rnd.chance(0.6) ? addDays(inv.issueDate, rnd.pick([0, 14, 30])) : null;
    inv.style = { ...inv.style, money: 'en', date: rnd.pick(['en-long', 'en-short', 'iso']) };
    inv.lines = rnd.sample(spec.items, rnd.int(1, 2)).map((description) => ({ description, qty: 1, unit: money(rnd, spec.range), rate: 0 }));
    inv.notes.push(origin === 'us'
        ? 'Tax not charged: services supplied to an EU business customer. VAT to be accounted for by the recipient (reverse charge).'
        : 'Reverse charge – VAT to be accounted for by the recipient (Article 196, Council Directive 2006/112/EC).');
    inv.tags.push('extranjera', 'ingles', 'inversion-sujeto-pasivo');
    if (spec.currency !== 'EUR') {
        inv.tags.push(`divisa-${spec.currency.toLowerCase()}`);
    }
    if (origin === 'us') {
        // El "EU VAT" de una empresa no europea (régimen OSS) no es un NIF-IVA de un país: las reglas deben mandarla a revisión.
        inv.tags.push('nif-oss');
    }

    return inv;
}

/** Proveedor portugués: factura en portugués, autoliquidación. */
export function portugueseInvoice(rnd, client) {
    const inv = foreignInvoice(rnd, client, 'de');
    inv.lang = 'pt';
    inv.supplier = { name: 'Têxteis do Douro, Lda.', taxId: `PT5${rnd.digits(8)}`, address: { street: 'Rua da Indústria, 214', zip: '4470-123', city: 'Maia, Portugal', province: '' }, email: 'faturacao@texteisdouro.pt', phone: '+351 229 000 000', iban: null };
    inv.template = 'english';
    inv.number = `FT ${inv.issueDate.slice(0, 4)}/${rnd.int(100, 999)}`;
    inv.style = { ...inv.style, money: 'pt', date: 'pt-long' };
    inv.lines = [
        { description: 'Toalhas de mesa em algodão 140x140', qty: rnd.int(10, 40), unit: rnd.int(950, 1600), rate: 0 },
        { description: 'Guardanapos de algodão (pack 12)', qty: rnd.int(5, 20), unit: rnd.int(1400, 2100), rate: 0 },
    ];
    inv.notes = ['IVA – autoliquidação. Isento nos termos do artigo 14.º do RITI.'];
    inv.tags = ['extranjera', 'portugues', 'inversion-sujeto-pasivo'];

    return inv;
}

/** Factura emitida por el propio cliente de la gestoría (la regla de dirección debe verla). */
export function issuedInvoice(rnd, client) {
    const inv = base(rnd, client);
    const customer = rnd.chance(0.5) ? makePerson(rnd) : makeCompany(rnd, rnd.pick(['Reparto Urbano Exprés', 'Construcciones Alameda', 'Floristería Jardín de Invierno']));

    inv.supplier = client;
    inv.customer = customer;
    inv.template = rnd.pick(['classic', 'modern', 'erp']);
    inv.dueDate = dueDate(rnd, inv.issueDate);
    inv.vehicle = `${rnd.digits(4)} ${rnd.pick(['KLM', 'HBD', 'GTR', 'MPN'])}`;
    inv.lines = [
        ...rnd.sample(WHOLESALERS.garage.items, rnd.int(1, 4)).map(([description, unit]) => ({ description, qty: rnd.int(1, 4), unit: round(unit * 1.4), rate: 21 })),
        { description: 'Mano de obra (horas)', qty: rnd.pick([0.5, 1, 1.5, 2, 2.5, 3.5]), unit: rnd.pick([3800, 4200, 4500]), rate: 21 },
    ];
    inv.payment = payment(rnd, client, inv.dueDate);
    inv.tags.push('emitida');

    return inv;
}

// ─── Errores del proveedor (la verdad es lo que pone el papel) ───────────

/** El total impreso no cuadra con la suma (las reglas deben pararla). */
export function wrongTotalInvoice(rnd, client) {
    const inv = serviceInvoice(rnd, client);
    inv.totalError = rnd.pick([100, 1000, -100, 900, 10]);
    inv.tags = ['error-proveedor', 'total-no-cuadra'];
    return inv;
}

/** CIF del proveedor con el dígito de control mal. */
export function badTaxIdInvoice(rnd, client) {
    const inv = serviceInvoice(rnd, client);
    inv.supplier = { ...inv.supplier, taxId: makeBadCif(rnd) };
    inv.tags = ['error-proveedor', 'cif-invalido'];
    return inv;
}

/** Programa de facturación antiguo con el IVA al 18 %. */
export function oldRateInvoice(rnd, client) {
    const inv = serviceInvoice(rnd, client);
    inv.lines = inv.lines.map((line) => ({ ...line, rate: 18 }));
    inv.tags = ['error-proveedor', 'tipo-iva-invalido'];
    return inv;
}

// ─── Documentos que no son facturas ──────────────────────────────────────

export function deliveryNote(rnd, client) {
    const inv = goodsInvoice(rnd, client, { lines: rnd.int(3, 9) });
    inv.kind = 'delivery_note';
    inv.number = `ALB-${rnd.digits(6)}`;
    inv.showPrices = rnd.chance(0.4);
    inv.notes = ['Recibí conforme. Firma y sello del cliente:', 'Este documento no es una factura.'];
    inv.tags = ['no-factura', 'albaran'];
    return inv;
}

export function quote(rnd, client, proforma = false) {
    const inv = rnd.chance(0.5) ? serviceInvoice(rnd, client) : goodsInvoice(rnd, client, { lines: rnd.int(2, 6) });
    inv.kind = 'quote';
    inv.proforma = proforma;
    inv.number = proforma ? `PRO-${rnd.digits(5)}` : `P-${inv.issueDate.slice(0, 4)}-${rnd.digits(3)}`;
    inv.dueDate = null;
    inv.notes = proforma
        ? ['Documento sin validez fiscal. La factura definitiva se emitirá tras el pago.']
        : [`Validez del presupuesto: ${rnd.pick([15, 30, 60])} días.`, 'Forma de pago: 50 % a la aceptación y 50 % a la finalización.'];
    inv.tags = ['no-factura', proforma ? 'proforma' : 'presupuesto'];
    return inv;
}

export function contract(rnd, client) {
    const inv = serviceInvoice(rnd, client);
    inv.kind = 'other';
    inv.template = 'contract';
    inv.tags = ['no-factura', 'contrato'];
    return inv;
}

export function payslip(rnd, client) {
    const inv = base(rnd, client, 'other');
    inv.supplier = client;
    inv.employee = { name: makePersonName(rnd), nif: makePerson(rnd).taxId, nss: `${rnd.digits(2)}/${rnd.digits(8)}/${rnd.digits(2)}`, category: rnd.pick(['Oficial de 1ª', 'Ayudante de cocina', 'Dependienta', 'Administrativo']) };
    inv.salary = money(rnd, [120000, 210000]);
    inv.template = 'payslip';
    inv.tags = ['no-factura', 'nomina'];
    return inv;
}

export function flyer(rnd, client) {
    const inv = goodsInvoice(rnd, client, { lines: 6 });
    inv.kind = 'other';
    inv.template = 'flyer';
    inv.tags = ['no-factura', 'publicidad'];
    return inv;
}

