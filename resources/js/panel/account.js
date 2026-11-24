/**
 * Datos de la cuenta que cambian cómo se ve el panel. Vienen en el arranque
 * (`boot`), así que no cambian mientras la página esté abierta.
 */

import { inject } from 'vue';

/**
 * Empresa única (autónomo o pyme sin gestoría): todo lo que llega es de su
 * empresa, así que el panel no enseña selectores ni columnas de "cliente".
 */
export function isSingleCompany(boot) {
    return boot?.account?.single_company === true;
}

export function useSingleCompany() {
    return isSingleCompany(inject('boot', null));
}
