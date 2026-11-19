<?php

namespace App\Enums;

enum InboundEmailStatus: string
{
    /** Guardado, a la espera de que el job lo procese. */
    case Pending = 'pending';

    /** Procesado: se han creado documentos (o todos eran duplicados). */
    case Processed = 'processed';

    /** Sin adjuntos válidos ni enlaces de descarga: nada que procesar. */
    case NoDocuments = 'no_documents';

    /** Sin token válido en la dirección: nadie sabe de quién es. */
    case Unrouted = 'unrouted';

    /** Correo de Gmail para confirmar la regla de reenvío. */
    case ForwardingConfirmation = 'forwarding_confirmation';

    /** Error técnico al procesarlo. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Processed => 'Procesado',
            self::NoDocuments => 'Sin documentos',
            self::Unrouted => 'Sin asignar a ninguna cuenta',
            self::ForwardingConfirmation => 'Confirmación de reenvío',
            self::Failed => 'Fallido',
        };
    }
}
