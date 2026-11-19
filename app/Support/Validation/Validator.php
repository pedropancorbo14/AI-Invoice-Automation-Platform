<?php

namespace App\Support\Validation;

use App\Support\Validation\Rules\ArithmeticRule;
use App\Support\Validation\Rules\ConfidenceRule;
use App\Support\Validation\Rules\DatesRule;
use App\Support\Validation\Rules\DirectionRule;
use App\Support\Validation\Rules\DuplicateRule;
use App\Support\Validation\Rules\NewSupplierRule;
use App\Support\Validation\Rules\RequiredFieldsRule;
use App\Support\Validation\Rules\TaxIdRule;
use App\Support\Validation\Rules\TaxRatesRule;

/**
 * Aplica todas las reglas y devuelve los motivos por los que una factura no
 * puede pasar sola. Sin motivos, la factura se valida automáticamente.
 */
class Validator
{
    /**
     * Reglas que solo miran los datos de la factura.
     *
     * @var list<class-string<Rule>>
     */
    public const STATELESS_RULES = [
        RequiredFieldsRule::class,
        TaxIdRule::class,
        DatesRule::class,
        TaxRatesRule::class,
        ArithmeticRule::class,
        DirectionRule::class,
        ConfidenceRule::class,
    ];

    /**
     * Reglas que consultan la cuenta (duplicados, proveedores conocidos).
     * Se saltan si la entrada no trae cuenta (p. ej. en invoices:eval).
     *
     * @var list<class-string<Rule>>
     */
    public const CONTEXT_RULES = [
        DuplicateRule::class,
        NewSupplierRule::class,
    ];

    public function validate(ValidationInput $input): ValidationReport
    {
        $rules = $input->accountId === null
            ? self::STATELESS_RULES
            : [...self::STATELESS_RULES, ...self::CONTEXT_RULES];

        $failures = [];

        foreach ($rules as $rule) {
            array_push($failures, ...app($rule)->check($input));
        }

        return new ValidationReport($failures, DirectionRule::resolve($input));
    }
}
