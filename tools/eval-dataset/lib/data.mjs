// Datos ficticios: empresas, personas, direcciones y catálogos por sector.
// Ningún nombre ni identificador corresponde a nadie real a propósito; los
// CIF y NIF se generan con un dígito de control válido.

const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';
const CIF_LETTERS = 'JABCDEFGHI';

export function cifControl(letter, digits) {
    let sum = 0;
    for (let i = 0; i < 7; i++) {
        const digit = Number(digits[i]);
        if (i % 2 === 0) {
            const doubled = digit * 2;
            sum += Math.floor(doubled / 10) + (doubled % 10);
        } else {
            sum += digit;
        }
    }
    const control = (10 - (sum % 10)) % 10;
    return 'NPQRSW'.includes(letter) ? CIF_LETTERS[control] : String(control);
}

export function makeCif(rnd, letter = 'B') {
    const digits = String(rnd.int(1, 9)) + rnd.digits(6);
    return `${letter}${digits}${cifControl(letter, digits)}`;
}

/** CIF con el dígito de control mal (error de tecleo del proveedor). */
export function makeBadCif(rnd, letter = 'B') {
    const good = makeCif(rnd, letter);
    const wrong = (Number(good[8]) + rnd.int(1, 8)) % 10;
    return good.slice(0, 8) + wrong;
}

export function makeNif(rnd) {
    const number = rnd.int(10000000, 79999999);
    return `${number}${DNI_LETTERS[number % 23]}`;
}

export function makeNie(rnd) {
    const prefix = rnd.pick(['X', 'Y', 'Z']);
    const digits = rnd.digits(7);
    const number = Number(`${'XYZ'.indexOf(prefix)}${digits}`);
    return `${prefix}${digits}${DNI_LETTERS[number % 23]}`;
}

export function makeIban(rnd) {
    return `ES${rnd.digits(2)} ${rnd.digits(4)} ${rnd.digits(4)} ${rnd.digits(2)}${rnd.digits(2)} ${rnd.digits(4)} ${rnd.digits(4)}`;
}

/** Cómo se imprime el identificador: "B12345674", "B-12345674", "B 1234567 4"... */
export function printTaxId(rnd, id) {
    if (!/^[A-Z]\d{7}[0-9A-J]$/.test(id) && !/^\d{8}[A-Z]$/.test(id)) {
        return id;
    }
    return rnd.pick([id, id, id, `${id[0]}-${id.slice(1)}`, id.replace(/^(\d{8})([A-Z])$/, '$1-$2')]);
}

const STREETS = [
    'Calle Mayor', 'Avenida de la Constitución', 'Calle Real', 'Paseo de la Estación', 'Calle San Roque',
    'Polígono Industrial Los Llanos, nave', 'Calle Doctor Fleming', 'Avenida de Andalucía', 'Calle Alfarería',
    'Ronda de Poniente', 'Calle Cervantes', 'Camino del Molino', 'Calle Jacinto Benavente', 'Plaza del Carmen',
    'Calle Industria', 'Avenida del Mediterráneo', 'Calle de los Herreros', 'Polígono El Prado, parcela',
];

const CITIES = [
    ['28013', 'Madrid', 'Madrid'], ['46002', 'Valencia', 'Valencia'], ['41004', 'Sevilla', 'Sevilla'],
    ['50001', 'Zaragoza', 'Zaragoza'], ['29005', 'Málaga', 'Málaga'], ['30001', 'Murcia', 'Murcia'],
    ['03001', 'Alicante', 'Alicante'], ['47001', 'Valladolid', 'Valladolid'], ['14002', 'Córdoba', 'Córdoba'],
    ['18001', 'Granada', 'Granada'], ['37002', 'Salamanca', 'Salamanca'], ['26001', 'Logroño', 'La Rioja'],
    ['08013', 'Barcelona', 'Barcelona'], ['48001', 'Bilbao', 'Bizkaia'], ['15003', 'A Coruña', 'A Coruña'],
    ['28918', 'Leganés', 'Madrid'], ['46900', 'Torrent', 'Valencia'], ['41700', 'Dos Hermanas', 'Sevilla'],
];

export function makeAddress(rnd) {
    const [zip, city, province] = rnd.pick(CITIES);
    return {
        street: `${rnd.pick(STREETS)} ${rnd.int(1, 140)}`,
        zip,
        city,
        province,
    };
}

const FIRST_NAMES = [
    'Javier', 'Lucía', 'Marta', 'Andrés', 'Carmen', 'Raúl', 'Elena', 'Pablo', 'Nuria', 'Sergio', 'Irene',
    'Alberto', 'Rocío', 'Diego', 'Beatriz', 'Óscar', 'Silvia', 'Gonzalo', 'Patricia', 'Héctor', 'Ainhoa',
];
const SURNAMES = [
    'Navarro', 'Domínguez', 'Gil', 'Serrano', 'Molina', 'Ortega', 'Rubio', 'Marín', 'Iglesias', 'Medina',
    'Garrido', 'Cortés', 'Castillo', 'Lozano', 'Guerrero', 'Cano', 'Prieto', 'Méndez', 'Calvo', 'Vidal',
    'Fuentes', 'Herrera', 'Peña', 'Soler', 'Pastor', 'Vicente', 'Carmona', 'Crespo', 'Román', 'Benítez',
];

export function makePersonName(rnd) {
    const [a, b] = rnd.sample(SURNAMES, 2);
    return `${rnd.pick(FIRST_NAMES)} ${a} ${b}`;
}

export function makePerson(rnd, { nie = false } = {}) {
    return {
        name: makePersonName(rnd),
        taxId: nie ? makeNie(rnd) : makeNif(rnd),
        address: makeAddress(rnd),
        email: null,
        phone: `6${rnd.digits(2)} ${rnd.digits(3)} ${rnd.digits(3)}`,
        iban: makeIban(rnd),
        person: true,
    };
}

function slug(text) {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '')
        .slice(0, 18);
}

/** Empresa ficticia: nombre legal, nombre comercial opcional, CIF y contacto. */
export function makeCompany(rnd, base, { legalForm, letter, commercial } = {}) {
    const form = legalForm ?? rnd.pick(['S.L.', 'S.L.', 'S.L.', 'S.L.U.', 'S.A.']);
    const cifLetter = letter ?? (form.startsWith('S.A') ? 'A' : 'B');
    const name = `${base} ${form}`;
    return {
        name,
        commercialName: commercial ?? null,
        taxId: makeCif(rnd, cifLetter),
        address: makeAddress(rnd),
        email: `${rnd.pick(['info', 'administracion', 'facturacion', 'contacto'])}@${slug(commercial ?? base)}.es`,
        phone: `9${rnd.digits(2)} ${rnd.digits(3)} ${rnd.digits(3)}`,
        iban: makeIban(rnd),
        registry: rnd.chance(0.5)
            ? `Inscrita en el Registro Mercantil de ${rnd.pick(['Madrid', 'Valencia', 'Sevilla', 'Barcelona', 'Málaga'])}, Tomo ${rnd.int(1000, 45000)}, Folio ${rnd.int(1, 220)}, Hoja ${rnd.pick(['M', 'V', 'SE', 'B', 'MA'])}-${rnd.int(10000, 699999)}`
            : null,
        person: false,
    };
}

/**
 * Los clientes de la gestoría: la "carpeta" en la que entra cada factura.
 * Se crean una vez por dataset para que sus CIF sean coherentes.
 */
export function makeClients(rnd) {
    const bakery = makeCompany(rnd, 'Obrador La Espiga Dorada', { legalForm: 'S.L.' });
    const restaurant = makeCompany(rnd, 'Hostelería Puerto Viejo', { legalForm: 'S.L.', commercial: 'Restaurante El Puerto' });
    const studio = makeCompany(rnd, 'Estudio Norte Arquitectura', { legalForm: 'S.L.P.' });
    const garage = makeCompany(rnd, 'Talleres Hermanos Olmedo', { legalForm: 'S.L.' });
    const shop = { ...makePerson(rnd), commercialName: 'Regalos Alhambra' };

    return {
        bakery: { ...bakery, sector: 'bakery' },
        restaurant: { ...restaurant, sector: 'restaurant' },
        studio: { ...studio, sector: 'studio' },
        garage: { ...garage, sector: 'garage' },
        // Autónoma en recargo de equivalencia (comercio minorista).
        shop: { ...shop, sector: 'shop' },
    };
}

/** Proveedores de servicios habituales (21 %). */
export const SERVICE_SUPPLIERS = [
    { base: 'Limpiezas Brillo Total', items: ['Servicio de limpieza mensual de local', 'Limpieza de cristales', 'Desinfección y tratamiento de suelos'], range: [9000, 42000] },
    { base: 'Mantenimientos Frío Industrial', items: ['Revisión periódica cámara frigorífica', 'Recarga de gas refrigerante R-134a', 'Sustitución de ventilador condensador', 'Mano de obra técnico (horas)'], range: [6000, 38000] },
    { base: 'Gestión de Plagas Levante', items: ['Tratamiento DDD trimestral', 'Revisión de cebos y trampas', 'Certificado de desinsectación'], range: [7000, 18000] },
    { base: 'Soluciones Web Kodalia', commercial: 'Kodalia', items: ['Mantenimiento web mensual', 'Alojamiento y dominio (anual)', 'Campaña de publicidad en redes', 'Diseño de cartelería'], range: [4500, 65000] },
    { base: 'Extintores y Seguridad Centro', items: ['Revisión anual de extintores', 'Retimbrado extintor 6 kg polvo ABC', 'Señalización de evacuación'], range: [3500, 22000] },
    { base: 'Transportes Hermanos Lafuente', items: ['Porte Valencia - Madrid', 'Servicio de paquetería urgente', 'Recargo combustible'], range: [2500, 30000] },
    { base: 'Informática Dataxis', commercial: 'Dataxis', items: ['Soporte informático (bono 5 h)', 'Licencia antivirus 3 puestos', 'Configuración router y red wifi', 'Copia de seguridad en la nube'], range: [3900, 48000] },
    { base: 'Lavandería Industrial Sur', items: ['Lavado mantelería (kg)', 'Alquiler de uniformes', 'Lavado de servilletas de tela'], range: [6000, 27000] },
];

/** Profesionales autónomos que facturan con retención de IRPF. */
export const PROFESSIONAL_SERVICES = [
    ['Honorarios asesoría fiscal y contable', 'Presentación de impuestos trimestrales'],
    ['Diseño de identidad corporativa', 'Adaptación del logotipo a redes sociales'],
    ['Dirección de obra reforma de local', 'Redacción de memoria técnica'],
    ['Servicios jurídicos: reclamación de cantidad', 'Revisión de contrato de arrendamiento'],
    ['Fotografía de producto (sesión de medio día)', 'Edición y retoque de 40 fotografías'],
    ['Formación interna en prevención de riesgos', 'Elaboración de la documentación de PRL'],
    ['Traducción jurada inglés-español', 'Revisión de textos de la web'],
];

/**
 * Mayoristas por sector. Cada artículo: [descripción, precio unitario en
 * céntimos, tipo de IVA, unidad]. Los tipos son los reales de cada producto.
 */
export const WHOLESALERS = {
    bakery: {
        names: ['Harinas y Derivados del Duero', 'Distribuciones Panaderas Ruiz', 'Envases y Embalajes Mediterráneo'],
        items: [
            ['Harina de trigo panificable T-65 saco 25 kg', 1890, 4, 'saco'],
            ['Harina integral de trigo saco 25 kg', 2340, 4, 'saco'],
            ['Levadura fresca prensada 500 g', 165, 10, 'ud'],
            ['Mantequilla 82 % MG bloque 1 kg', 945, 10, 'kg'],
            ['Azúcar blanco saco 25 kg', 2650, 10, 'saco'],
            ['Huevo líquido pasteurizado 1 L', 389, 10, 'ud'],
            ['Aceite de oliva virgen extra 5 L', 3475, 4, 'ud'],
            ['Chocolate cobertura 55 % 5 kg', 6290, 10, 'ud'],
            ['Bolsa de papel kraft pan (1000 uds)', 3120, 21, 'caja'],
            ['Caja de cartón para tartas 28x28 (50 uds)', 1875, 21, 'paq'],
            ['Papel de horno siliconado (500 hojas)', 4200, 21, 'caja'],
            ['Mejorante panario 10 kg', 4815, 10, 'ud'],
            ['Sal fina saco 25 kg', 690, 10, 'saco'],
        ],
    },
    restaurant: {
        names: ['Pescados y Mariscos La Lonja', 'Frutas y Verduras Hermanos Peral', 'Distribuciones de Bebidas Costa Azul', 'Cárnicas Selectas del Valle'],
        items: [
            ['Merluza de pincho fresca', 1490, 10, 'kg'],
            ['Calamar de potera', 1875, 10, 'kg'],
            ['Gamba roja', 4890, 10, 'kg'],
            ['Tomate pera', 185, 4, 'kg'],
            ['Patata agria saco 25 kg', 1350, 4, 'saco'],
            ['Lechuga romana', 75, 4, 'ud'],
            ['Limones', 210, 4, 'kg'],
            ['Pan de pueblo 500 g', 140, 4, 'ud'],
            ['Aceite de oliva virgen extra 5 L', 3475, 4, 'ud'],
            ['Solomillo de ternera', 2890, 10, 'kg'],
            ['Pechuga de pollo fileteada', 745, 10, 'kg'],
            ['Agua mineral 1,5 L (pack 6)', 290, 10, 'pack'],
            ['Refresco de cola lata 33 cl (24 uds)', 1490, 10, 'caja'],
            ['Cerveza barril 30 L', 8950, 21, 'barril'],
            ['Vino tinto crianza D.O. Rioja 75 cl', 685, 21, 'bot'],
            ['Café en grano mezcla 1 kg', 1690, 10, 'kg'],
            ['Detergente lavavajillas industrial 20 L', 5490, 21, 'garrafa'],
            ['Servilletas de papel 40x40 (1200 uds)', 2280, 21, 'caja'],
        ],
    },
    studio: {
        names: ['Papelería Técnica Delineantes', 'Suministros de Oficina Tinta y Papel', 'Mobiliario Ofinova'],
        items: [
            ['Papel A4 80 g (caja 5 paquetes)', 2190, 21, 'caja'],
            ['Rollo papel plotter 914 mm x 50 m', 3450, 21, 'rollo'],
            ['Cartucho de tinta plotter negro 300 ml', 11890, 21, 'ud'],
            ['Cuaderno de bocetos A3', 890, 21, 'ud'],
            ['Silla ergonómica de oficina', 18900, 21, 'ud'],
            ['Monitor 27" QHD', 26900, 21, 'ud'],
            ['Archivador de palanca lomo ancho', 385, 21, 'ud'],
            ['Maqueta: plancha de cartón pluma 5 mm', 420, 21, 'ud'],
            ['Libro técnico: Código Técnico de la Edificación', 4800, 4, 'ud'],
            ['Revista de arquitectura (suscripción anual)', 9600, 4, 'ud'],
        ],
    },
    garage: {
        names: ['Recambios del Automóvil Motorsur', 'Lubricantes y Filtros Ibérica', 'Neumáticos Rueda Fácil'],
        items: [
            ['Aceite motor 5W30 sintético 5 L', 3290, 21, 'ud'],
            ['Filtro de aceite', 645, 21, 'ud'],
            ['Filtro de aire', 1180, 21, 'ud'],
            ['Pastillas de freno delanteras', 3150, 21, 'juego'],
            ['Disco de freno ventilado', 4490, 21, 'ud'],
            ['Neumático 205/55 R16 91V', 6890, 21, 'ud'],
            ['Batería 70 Ah 640 A', 9450, 21, 'ud'],
            ['Líquido refrigerante 5 L', 1290, 21, 'ud'],
            ['Escobilla limpiaparabrisas 600 mm', 890, 21, 'ud'],
            ['Kit de distribución', 15600, 21, 'kit'],
            ['Bombilla H7 12 V 55 W', 390, 21, 'ud'],
        ],
    },
    shop: {
        names: ['Importaciones Artesanía Oriente', 'Distribuidora de Regalos Brisa', 'Cerámicas Artesanas de Manises', 'Dulces y Golosinas El Caramelo'],
        items: [
            ['Taza cerámica decorada 350 ml', 245, 21, 'ud'],
            ['Vela aromática en tarro de cristal', 390, 21, 'ud'],
            ['Marco de fotos madera 13x18', 310, 21, 'ud'],
            ['Imán de nevera souvenir surtido', 65, 21, 'ud'],
            ['Llavero metálico grabado', 120, 21, 'ud'],
            ['Libro de recetas tradicionales', 1150, 4, 'ud'],
            ['Agenda 2027 tapa dura', 590, 21, 'ud'],
            ['Caja de turrón artesano 300 g', 520, 10, 'ud'],
            ['Bolsa de caramelos surtidos 1 kg', 690, 10, 'ud'],
            ['Tarro de miel de romero 500 g', 480, 10, 'ud'],
            ['Cuento infantil ilustrado', 980, 4, 'ud'],
        ],
    },
};

/** Recargo de equivalencia que corresponde a cada tipo de IVA. */
export const SURCHARGE = { 21: 5.2, 10: 1.4, 4: 0.5, 0: 0 };
