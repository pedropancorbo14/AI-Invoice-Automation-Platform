<?php

namespace App\Support\Inbox;

use App\Enums\AttachmentOutcome;

/**
 * Decide si un adjunto puede ser una factura o es ruido (firmas, logos,
 * iconos de redes sociales, .ics, firmas digitales...).
 */
class AttachmentFilter
{
    /**
     * Devuelve el tipo detectado si se acepta, o el motivo por el que se descarta.
     */
    public function evaluate(EmailAttachment $attachment): FileType|AttachmentOutcome
    {
        $config = (array) config('invoices.inbox.attachments');

        if ($attachment->size() > (int) $config['max_bytes']) {
            return AttachmentOutcome::TooLarge;
        }

        $type = FileType::detect($attachment->content);

        if ($type === null) {
            return AttachmentOutcome::Unsupported;
        }

        if ($type->isImage() && $this->looksLikeDecoration($attachment, $type, $config)) {
            return AttachmentOutcome::TooSmall;
        }

        return $type;
    }

    /**
     * Si se pueden leer las dimensiones, mandan ellas: un escaneo en PNG puede
     * pesar muy poco y seguir siendo un A4. El peso solo decide cuando no se
     * pueden leer (HEIC, que getimagesize no entiende).
     *
     * @param  array<string, mixed>  $config
     */
    private function looksLikeDecoration(EmailAttachment $attachment, FileType $type, array $config): bool
    {
        $size = $type === FileType::Heic ? false : @getimagesizefromstring($attachment->content);

        if ($size === false) {
            return $attachment->size() < (int) $config['min_image_bytes'];
        }

        $minDimension = (int) $config['min_image_dimension'];

        return $size[0] < $minDimension || $size[1] < $minDimension;
    }
}
