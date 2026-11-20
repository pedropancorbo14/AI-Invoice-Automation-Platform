// Aleatorio con semilla: el mismo --seed genera siempre el mismo dataset.

export function createRandom(seed) {
    // Se mezcla la semilla para que semillas seguidas (1, 2, 3...) no den secuencias parecidas.
    let state = Math.imul((seed >>> 0) ^ 0x9e3779b9, 0x85ebca6b) >>> 0;
    state = Math.imul(state ^ (state >>> 13), 0xc2b2ae35) >>> 0;

    const next = () => {
        // mulberry32
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };

    for (let i = 0; i < 4; i++) {
        next();
    }

    const rnd = {
        next,
        int: (min, max) => min + Math.floor(next() * (max - min + 1)),
        float: (min, max) => min + next() * (max - min),
        chance: (p) => next() < p,
        pick: (list) => list[Math.floor(next() * list.length)],
        digits: (n) => Array.from({ length: n }, () => Math.floor(next() * 10)).join(''),
        sample: (list, n) => {
            const copy = [...list];
            const out = [];
            while (out.length < n && copy.length > 0) {
                out.push(copy.splice(Math.floor(next() * copy.length), 1)[0]);
            }
            return out;
        },
    };

    return rnd;
}
