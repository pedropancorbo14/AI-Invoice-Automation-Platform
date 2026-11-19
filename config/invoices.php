<?php

return [

    /*
    | Disco privado donde se guardan los documentos originales.
    | "documents" es local (storage/app/private/documents); pon "s3" para
    | usar un almacenamiento compatible con S3 (MinIO, R2, Hetzner...).
    */
    'documents_disk' => env('DOCUMENTS_DISK', 'documents'),

    /*
    | Emails (separados por comas) con acceso a Horizon en producción.
    */
    'operator_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPERATOR_EMAILS', '')),
    ))),

    /*
    | Buzón de entrada. Cada cliente tiene su dirección con subdirección:
    | facturas+<token>@tudominio.com. El buzón real es INBOX_ADDRESS.
    */
    'inbox' => [
        'enabled' => (bool) env('INBOX_ENABLED', false),
        'address' => env('INBOX_ADDRESS', 'facturas@example.com'),

        'imap' => [
            'host' => env('INBOX_IMAP_HOST'),
            'port' => (int) env('INBOX_IMAP_PORT', 993),
            'encryption' => env('INBOX_IMAP_ENCRYPTION', 'ssl'),
            'validate_cert' => (bool) env('INBOX_IMAP_VALIDATE_CERT', true),
            'username' => env('INBOX_IMAP_USERNAME'),
            'password' => env('INBOX_IMAP_PASSWORD'),
            'folder' => env('INBOX_IMAP_FOLDER', 'INBOX'),
            // Los correos ya leídos se mueven aquí (se crea si no existe).
            'processed_folder' => env('INBOX_IMAP_PROCESSED_FOLDER', 'Procesados'),
            // Máximo de correos por ejecución, para no bloquear el scheduler.
            'batch_size' => (int) env('INBOX_IMAP_BATCH_SIZE', 50),
        ],

        'attachments' => [
            // Imágenes más pequeñas que esto (en px) se descartan: firmas, logos, iconos.
            'min_image_dimension' => (int) env('INBOX_MIN_IMAGE_DIMENSION', 300),
            // Si no se pueden leer las dimensiones (HEIC), se usa el peso.
            'min_image_bytes' => (int) env('INBOX_MIN_IMAGE_BYTES', 15 * 1024),
            'max_bytes' => (int) env('INBOX_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),
        ],
    ],

    /*
    | Extracción con IA (fase 3). La IA extrae; Laravel decide qué hacer.
    */
    'extraction' => [
        'enabled' => (bool) env('EXTRACTION_ENABLED', true),

        // Se puede cambiar por modelo para comparar coste y precisión con invoices:eval.
        'model' => env('EXTRACTION_MODEL', 'claude-opus-5-5'),
        // low | medium | high | xhigh | max. Más alto = más preciso y más caro.
        'effort' => env('EXTRACTION_EFFORT', 'medium'),
        'max_tokens' => (int) env('EXTRACTION_MAX_TOKENS', 16000),
        'timeout' => (float) env('EXTRACTION_TIMEOUT', 120),

        // Si el PDF tiene capa de texto se manda el texto (mucho más barato);
        // si no (escaneo), el PDF entero.
        'prefer_text' => (bool) env('EXTRACTION_PREFER_TEXT', true),
        'min_text_chars' => (int) env('EXTRACTION_MIN_TEXT_CHARS', 200),

        // Las fotos se reducen a este lado máximo (px) antes de mandarlas.
        'max_image_side' => (int) env('EXTRACTION_MAX_IMAGE_SIDE', 2000),

        // Qué hacer según el tipo de documento que detecta la IA.
        'ignored_types' => ['other'],
        'review_types' => ['delivery_note', 'quote'],

        // Precios en USD por millón de tokens (para el coste por factura).
        'pricing' => [
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.10],
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01],
        ],
    ],

];
