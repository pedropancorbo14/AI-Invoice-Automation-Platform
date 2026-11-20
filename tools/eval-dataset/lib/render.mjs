// Pinta las plantillas con Chromium (Playwright) y simula cómo llegan las
// facturas de verdad: PDF digital con texto, escaneo (PDF solo imagen,
// torcido y con ruido), foto de móvil (perspectiva, sombras, desenfoque) y
// captura de pantalla. Todo se hace en el navegador: no hace falta ImageMagick.

import { createRequire } from 'node:module';

// Con require() también se encuentra un Playwright instalado de forma global.
const { chromium } = createRequire(import.meta.url)('playwright');

export async function createRenderer() {
    const browser = await chromium.launch();
    const context = await browser.newContext({ deviceScaleFactor: 1 });

    async function withPage(fn, options = {}) {
        const page = options.deviceScaleFactor
            ? await browser.newPage({ deviceScaleFactor: options.deviceScaleFactor, viewport: options.viewport })
            : await context.newPage();
        try {
            if (options.viewport && !options.deviceScaleFactor) {
                await page.setViewportSize(options.viewport);
            }
            return await fn(page);
        } finally {
            await page.close();
        }
    }

    /** PDF con capa de texto, como el que sale de un programa de facturación. */
    async function pdf(html, { ticket = false } = {}) {
        return withPage(async (page) => {
            await page.setContent(html, { waitUntil: 'load' });
            await page.evaluate(() => document.fonts.ready);
            if (ticket) {
                const height = await page.evaluate(() => document.querySelector('.page').scrollHeight);
                return page.pdf({ width: '80mm', height: `${Math.ceil(height) + 4}px`, printBackground: true });
            }
            return page.pdf({ format: 'A4', printBackground: true, preferCSSPageSize: true });
        }, { viewport: { width: 794, height: 1123 } });
    }

    /** Cada página como PNG (≈190 ppp): la "hoja de papel" que luego se escanea o se fotografía. */
    async function sheets(html, { ticket = false } = {}) {
        return withPage(async (page) => {
            await page.setContent(html, { waitUntil: 'load' });
            await page.evaluate(() => document.fonts.ready);
            const pages = await page.locator('.page').all();
            const shots = [];
            for (const sheet of pages) {
                shots.push(await sheet.screenshot({ type: 'png' }));
            }
            return shots;
        }, { deviceScaleFactor: 2, viewport: { width: ticket ? 302 : 794, height: 1123 } });
    }

    /** Escaneo: gris, algo torcido, ruido y bordes; luego un PDF solo con imágenes. */
    async function scan(pngs, rnd) {
        const images = [];
        for (const png of pngs) {
            const effect = {
                angle: rnd.float(-1.4, 1.4),
                dx: rnd.int(-14, 14),
                dy: rnd.int(-10, 18),
                contrast: rnd.float(1.15, 1.5),
                brightness: rnd.float(0.95, 1.08),
                blur: rnd.float(0.2, 0.75),
                noise: rnd.float(0.05, 0.13),
                edge: rnd.chance(0.5),
                seed: rnd.int(1, 1e9),
                gray: rnd.chance(0.8),
            };
            images.push(await degrade(png, 'scan', effect, { width: 1240, height: 1754 }, rnd.int(50, 72)));
        }

        const html = `<!doctype html><html><head><style>@page { size: A4; margin: 0; } body { margin: 0; } img { width: 210mm; height: 297mm; display: block; page-break-after: always; } img:last-child { page-break-after: auto; }</style></head><body>${images.map((jpg) => `<img src="data:image/jpeg;base64,${jpg.toString('base64')}">`).join('')}</body></html>`;

        return withPage(async (page) => {
            await page.setContent(html, { waitUntil: 'load' });
            return page.pdf({ format: 'A4', printBackground: true });
        });
    }

    /** Foto de móvil: papel sobre una mesa, en perspectiva, con sombra y algo de desenfoque. */
    async function photo(png, rnd, { ticket = false } = {}) {
        const effect = {
            rx: rnd.float(-12, 14),
            ry: rnd.float(-10, 10),
            rz: rnd.float(-6, 6),
            scale: rnd.float(0.8, 0.9),
            blur: rnd.float(0.3, 1.1),
            noise: rnd.float(0.04, 0.1),
            shadow: rnd.float(0.15, 0.45),
            shadowAngle: rnd.int(0, 359),
            warm: rnd.float(0, 0.18),
            table: rnd.pick(['#6d4c35', '#8a6a4f', '#3b3b3b', '#c9c2b8', '#2f3e4e', '#a0a0a0']),
            seed: rnd.int(1, 1e9),
        };
        const size = ticket ? { width: 900, height: 1500 } : { width: 1500, height: 2000 };
        return degrade(png, 'photo', effect, size, rnd.int(70, 86));
    }

    /** Captura de pantalla de una factura abierta en el navegador (PNG, nítida). */
    async function screenshot(png) {
        return png;
    }

    async function degrade(png, mode, effect, size, quality) {
        const src = `data:image/png;base64,${png.toString('base64')}`;
        const html = mode === 'scan' ? scanHtml(src, effect, size) : photoHtml(src, effect, size);

        return withPage(async (page) => {
            await page.setContent(html, { waitUntil: 'load' });
            await page.evaluate(NOISE_SCRIPT, effect);
            return page.screenshot({ type: 'jpeg', quality, fullPage: false });
        }, { viewport: size });
    }

    return {
        pdf,
        sheets,
        scan,
        photo,
        screenshot,
        close: () => browser.close(),
    };
}

// Ruido de sensor o de escáner, determinista según la semilla.
const NOISE_SCRIPT = (effect) => {
    const canvas = document.getElementById('noise');
    const ctx = canvas.getContext('2d');
    const { width, height } = canvas;
    const data = ctx.createImageData(width, height);
    let s = effect.seed >>> 0;
    const rand = () => {
        s = (s + 0x6d2b79f5) >>> 0;
        let t = s;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    for (let i = 0; i < data.data.length; i += 4) {
        const v = rand() * 255;
        data.data[i] = v;
        data.data[i + 1] = v;
        data.data[i + 2] = v;
        data.data[i + 3] = rand() < 0.5 ? 255 * effect.noise : 0;
    }
    ctx.putImageData(data, 0, 0);
};

function scanHtml(src, e, { width, height }) {
    return `<!doctype html><html><head><style>
        html, body { margin: 0; width: ${width}px; height: ${height}px; overflow: hidden; background: #fbfbf8; }
        .sheet { position: absolute; inset: 0; transform: translate(${e.dx}px, ${e.dy}px) rotate(${e.angle}deg); transform-origin: 50% 50%; }
        .sheet img { width: 100%; height: 100%; filter: ${e.gray ? 'grayscale(1)' : 'saturate(.7)'} contrast(${e.contrast}) brightness(${e.brightness}) blur(${e.blur}px); }
        #noise { position: absolute; inset: 0; width: 100%; height: 100%; mix-blend-mode: multiply; }
        .edge { position: absolute; top: 0; bottom: 0; left: 0; width: 26px; background: linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,0)); }
    </style></head><body><div class="sheet"><img src="${src}"></div><canvas id="noise" width="${Math.round(width / 2)}" height="${Math.round(height / 2)}"></canvas>${e.edge ? '<div class="edge"></div>' : ''}</body></html>`;
}

function photoHtml(src, e, { width, height }) {
    return `<!doctype html><html><head><style>
        html, body { margin: 0; width: ${width}px; height: ${height}px; overflow: hidden;
            background: radial-gradient(circle at 30% 20%, ${e.table}, #000 160%); }
        .wrap { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; perspective: 1800px; }
        .sheet { position: relative; display: inline-block; transform: rotateX(${e.rx}deg) rotateY(${e.ry}deg) rotateZ(${e.rz}deg); box-shadow: 0 25px 60px rgba(0,0,0,.55); }
        .sheet img { display: block; max-width: ${Math.round(e.scale * 100)}vw; max-height: ${Math.round(e.scale * 100)}vh; filter: blur(${e.blur}px) contrast(1.05) sepia(${e.warm}); }
        .light { position: absolute; inset: 0; background: linear-gradient(${e.shadowAngle}deg, rgba(0,0,0,${e.shadow}), rgba(0,0,0,0) 55%, rgba(255,255,255,.08)); mix-blend-mode: multiply; }
        #noise { position: absolute; inset: 0; width: 100%; height: 100%; mix-blend-mode: overlay; }
    </style></head><body><div class="wrap"><div class="sheet"><img src="${src}"><div class="light"></div></div></div><canvas id="noise" width="${Math.round(width / 2)}" height="${Math.round(height / 2)}"></canvas></body></html>`;
}
