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

];
