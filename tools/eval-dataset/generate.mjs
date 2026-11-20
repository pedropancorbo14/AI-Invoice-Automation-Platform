#!/usr/bin/env node
// Genera un dataset SINTÉTICO para `php artisan invoices:eval`: facturas
// ficticias en PDF digital, escaneo, foto de móvil y captura, cada una con su
// .json de verdad anotada (formato de docs/evaluacion.md).
//
// Uso (desde la raíz del proyecto):
//   node tools/eval-dataset/generate.mjs [--out=storage/app/eval/synthetic] [--seed=2026] [--only=1,2,3]
//
// Sirve para poner a punto el prompt y las reglas antes de tener facturas
// reales y para detectar regresiones. NO sustituye al dataset real: los
// números que salgan de aquí no se deben publicar como resultados reales.

import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { makeClients, printTaxId } from './lib/data.mjs';
import { compute, expected } from './lib/invoice.mjs';
import { createRandom } from './lib/random.mjs';
import { createRenderer } from './lib/render.mjs';
import * as S from './lib/scenarios.mjs';
import { render } from './lib/templates.mjs';

const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
    const [key, value] = arg.replace(/^--/, '').split('=');
    return [key, value ?? true];
}));

const OUT = path.resolve(args.out ?? 'storage/app/eval/synthetic');
const SEED = Number(args.seed ?? 2026);
const ONLY = args.only ? new Set(String(args.only).split(',').map(Number)) : null;

// Reparto parecido al recomendado en docs/evaluacion.md:
// ~38 % PDF digital, ~21 % escaneo, ~30 % fotos (con tickets), ~9 % no facturas.
const PLAN = [
    // PDF digital
    [S.serviceInvoice, 'bakery', 'pdf'],
    [S.serviceInvoice, 'restaurant', 'pdf'],
    [S.serviceInvoice, 'studio', 'pdf'],
    [S.serviceInvoice, 'garage', 'pdf'],
    [S.serviceInvoice, 'restaurant', 'pdf'],
    [S.serviceInvoice, 'studio', 'pdf'],
    [S.goodsInvoice, 'restaurant', 'pdf'],
    [S.goodsInvoice, 'bakery', 'pdf'],
    [S.goodsInvoice, 'garage', 'pdf'],
    [S.goodsInvoice, 'restaurant', 'pdf', { lines: 38 }],
    [S.goodsInvoice, 'studio', 'pdf'],
    [S.professionalInvoice, 'studio', 'pdf'],
    [S.professionalInvoice, 'bakery', 'pdf'],
    [S.professionalInvoice, 'restaurant', 'pdf'],
    [S.rentInvoice, 'garage', 'pdf'],
    [S.electricityBill, 'bakery', 'pdf'],
    [S.electricityBill, 'restaurant', 'pdf'],
    [S.telecomBill, 'studio', 'pdf'],
    [S.waterBill, 'restaurant', 'pdf'],
    [S.surchargeInvoice, 'shop', 'pdf'],
    [S.surchargeInvoice, 'shop', 'pdf'],
    [S.exemptInvoice, 'restaurant', 'pdf'],
    [S.creditNote, 'restaurant', 'pdf'],
    [S.creditNote, 'garage', 'pdf'],
    [S.foreignInvoice, 'studio', 'pdf', 'ie'],
    [S.foreignInvoice, 'studio', 'pdf', 'us'],
    [S.foreignInvoice, 'restaurant', 'pdf', 'de'],
    [S.issuedInvoice, 'garage', 'pdf'],
    [S.issuedInvoice, 'garage', 'pdf'],
    [S.ticket, 'garage', 'pdf', 'gasolinera'],
    [S.ticket, 'studio', 'pdf', 'parking'],

    // Escaneos (PDF sin texto)
    [S.serviceInvoice, 'bakery', 'scan'],
    [S.serviceInvoice, 'garage', 'scan'],
    [S.serviceInvoice, 'restaurant', 'scan'],
    [S.goodsInvoice, 'bakery', 'scan'],
    [S.goodsInvoice, 'garage', 'scan'],
    [S.goodsInvoice, 'bakery', 'scan', { lines: 30 }],
    [S.professionalInvoice, 'garage', 'scan'],
    [S.professionalInvoice, 'studio', 'scan'],
    [S.professionalInvoice, 'shop', 'scan'],
    [S.surchargeInvoice, 'shop', 'scan'],
    [S.surchargeInvoice, 'shop', 'scan'],
    [S.creditNote, 'bakery', 'scan'],
    [S.electricityBill, 'garage', 'scan'],
    [S.issuedInvoice, 'garage', 'scan'],
    [S.wrongTotalInvoice, 'bakery', 'scan'],
    [S.badTaxIdInvoice, 'restaurant', 'scan'],
    [S.portugueseInvoice, 'restaurant', 'scan'],

    // Fotos de móvil
    [S.serviceInvoice, 'restaurant', 'photo'],
    [S.serviceInvoice, 'garage', 'photo'],
    [S.serviceInvoice, 'bakery', 'photo'],
    [S.goodsInvoice, 'restaurant', 'photo', { lines: 6 }],
    [S.goodsInvoice, 'bakery', 'photo', { lines: 5 }],
    [S.goodsInvoice, 'garage', 'photo', { lines: 4 }],
    [S.professionalInvoice, 'restaurant', 'photo'],
    [S.professionalInvoice, 'bakery', 'photo'],
    [S.surchargeInvoice, 'shop', 'photo'],
    [S.rentInvoice, 'bakery', 'photo'],
    [S.telecomBill, 'garage', 'photo'],
    [S.creditNote, 'studio', 'photo'],
    [S.oldRateInvoice, 'garage', 'photo'],
    [S.exemptInvoice, 'studio', 'photo'],
    [S.ticket, 'garage', 'photo', 'gasolinera'],
    [S.ticket, 'bakery', 'photo', 'gasolinera'],
    [S.ticket, 'studio', 'photo', 'bar'],
    [S.ticket, 'garage', 'photo', 'bar'],
    [S.ticket, 'restaurant', 'photo', 'supermercado'],
    [S.ticket, 'bakery', 'photo', 'supermercado'],
    [S.ticket, 'studio', 'photo', 'parking'],
    [S.ticket, 'garage', 'photo', 'ferreteria'],
    [S.ticket, 'restaurant', 'photo', 'ferreteria'],
    [S.ticket, 'shop', 'photo', 'bar'],

    // Capturas de pantalla
    [S.foreignInvoice, 'studio', 'png', 'us'],
    [S.serviceInvoice, 'studio', 'png'],

    // Documentos que no son facturas
    [S.deliveryNote, 'bakery', 'photo'],
    [S.deliveryNote, 'restaurant', 'scan'],
    [S.quote, 'restaurant', 'pdf'],
    [S.quote, 'garage', 'pdf', true],
    [S.contract, 'studio', 'scan'],
    [S.payslip, 'restaurant', 'pdf'],
    [S.flyer, 'shop', 'png'],
];

const FORMAT_TAGS = { pdf: 'pdf-digital', scan: 'pdf-escaneado', photo: 'foto', png: 'captura' };
const EXTENSIONS = { pdf: 'pdf', scan: 'pdf', photo: 'jpg', png: 'png' };

function slug(text) {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 40);
}

async function main() {
    const rnd = createRandom(SEED);
    const clients = makeClients(rnd);
    const renderer = await createRenderer();
    const summary = {};

    if (!ONLY) {
        await rm(OUT, { recursive: true, force: true });
    }
    await mkdir(OUT, { recursive: true });

    try {
        for (const [index, [scenario, clientKey, format, option]] of PLAN.entries()) {
            const n = index + 1;
            // Cada caso con su propia semilla: cambiar uno no altera los demás.
            const caseRnd = createRandom(SEED * 1000 + n);

            if (ONLY && !ONLY.has(n)) {
                continue;
            }

            const client = clients[clientKey];
            const inv = scenario(caseRnd, client, option);

            // El CIF del proveedor no siempre se imprime igual (B-12345678, con espacios...).
            inv.supplier = { ...inv.supplier, printedTaxId: printTaxId(caseRnd, inv.supplier.taxId ?? '') || null };

            const calc = compute(inv);
            const html = render(inv, calc);
            const isTicket = inv.template === 'ticket';
            const name = `${String(n).padStart(3, '0')}-${slug(`${inv.tags[0]} ${inv.ticketKind ?? ''} ${inv.supplier.commercialName ?? inv.supplier.name}`)}`;

            let file;
            if (format === 'pdf') {
                file = await renderer.pdf(html, { ticket: isTicket });
            } else {
                const sheets = await renderer.sheets(html, { ticket: isTicket });
                if (format !== 'scan' && sheets.length > 1) {
                    throw new Error(`${name}: una foto o captura debe ser de una sola página (tiene ${sheets.length}).`);
                }
                file = format === 'scan'
                    ? await renderer.scan(sheets, caseRnd)
                    : format === 'photo'
                        ? await renderer.photo(sheets[0], caseRnd, { ticket: isTicket })
                        : await renderer.screenshot(sheets[0]);
            }

            // La empresa en cuya carpeta entraría el documento (el mismo contexto que en producción).
            const company = inv.client ?? client;
            const truth = {
                tags: ['sintetica', FORMAT_TAGS[format], ...inv.tags],
                company: { name: company.name, tax_id: company.taxId },
                expected: expected(inv, calc),
            };

            await writeFile(path.join(OUT, `${name}.${EXTENSIONS[format]}`), file);
            await writeFile(path.join(OUT, `${name}.json`), `${JSON.stringify(truth, null, 2)}\n`);

            for (const tag of truth.tags) {
                summary[tag] = (summary[tag] ?? 0) + 1;
            }
            process.stdout.write(`  ${name}.${EXTENSIONS[format]}\n`);
        }
    } finally {
        await renderer.close();
    }

    const total = ONLY ? ONLY.size : PLAN.length;
    console.log(`\n${total} documentos en ${path.relative(process.cwd(), OUT) || OUT}`);
    console.log(Object.entries(summary).sort((a, b) => b[1] - a[1]).map(([tag, count]) => `  ${tag}: ${count}`).join('\n'));
    console.log('\nEvalúa con: php artisan invoices:eval --dataset=' + (path.relative(process.cwd(), OUT) || OUT));
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
