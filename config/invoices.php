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

];
