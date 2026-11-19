<?php

namespace App\Enums;

/**
 * Estados del ciclo de vida de un documento.
 *
 * Camino feliz: RECEIVED → CLASSIFIED → EXTRACTED → VALIDATED → EXPORTED.
 * Salidas: NEEDS_REVIEW (hay un problema), IGNORED (no es una factura)
 * y FAILED (error técnico).
 */
enum DocumentStatus: string
{
    case Received = 'received';
    case Classified = 'classified';
    case Extracted = 'extracted';
    case Validated = 'validated';
    case Exported = 'exported';
    case NeedsReview = 'needs_review';
    case Ignored = 'ignored';
    case Failed = 'failed';

    /**
     * Estados a los que se puede pasar desde este.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::Classified, self::NeedsReview, self::Ignored, self::Failed],
            self::Classified => [self::Extracted, self::NeedsReview, self::Ignored, self::Failed],
            self::Extracted => [self::Validated, self::NeedsReview, self::Failed],
            self::Validated => [self::Exported, self::NeedsReview, self::Failed],
            // Tras la revisión humana: se aprueba, se descarta o se reprocesa.
            self::NeedsReview => [self::Validated, self::Ignored, self::Received],
            // Un fallo técnico se puede reintentar o mandar a revisión.
            self::Failed => [self::Received, self::NeedsReview],
            // Un documento ignorado por error se puede reprocesar.
            self::Ignored => [self::Received],
            self::Exported => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Classified => 'Clasificado',
            self::Extracted => 'Extraído',
            self::Validated => 'Validado',
            self::Exported => 'Exportado',
            self::NeedsReview => 'Pendiente de revisión',
            self::Ignored => 'Ignorado',
            self::Failed => 'Fallido',
        };
    }
}
