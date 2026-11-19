<?php

namespace App\Support\Inbox;

use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Buzón IMAP real (Webklex, sin necesidad de la extensión imap de PHP).
 * Los correos leídos se mueven a la carpeta de procesados.
 */
class ImapMailbox implements Mailbox
{
    private ?Client $client = null;

    /**
     * @param  array<string, mixed>  $config  config('invoices.inbox.imap')
     */
    public function __construct(private readonly array $config) {}

    public function fetch(int $limit): iterable
    {
        $folder = $this->client()->getFolderByPath((string) $this->config['folder']);

        if ($folder === null) {
            throw new RuntimeException("No existe la carpeta IMAP «{$this->config['folder']}».");
        }

        $messages = $folder->messages()
            ->all()
            ->setFetchBody(true)
            ->leaveUnread()
            ->setFetchOrderAsc()
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            yield new MailboxMessage(
                id: (string) $message->getUid(),
                raw: $this->raw($message),
                handle: $message,
            );
        }
    }

    public function markProcessed(MailboxMessage $message): void
    {
        $handle = $message->handle;

        if (! $handle instanceof Message) {
            return;
        }

        $target = (string) $this->config['processed_folder'];

        if ($this->client()->getFolderByPath($target) === null) {
            $this->client()->createFolder($target);
        }

        // Si no se puede mover (permisos del servidor), al menos lo marcamos como leído.
        if ($handle->move($target, expunge: true) === null) {
            $handle->setFlag('Seen');
        }
    }

    private function raw(Message $message): string
    {
        $header = rtrim((string) $message->getHeader()?->raw, "\r\n");

        return $header."\r\n\r\n".$message->getRawBody();
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = (new ClientManager)->make([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'encryption' => $this->config['encryption'],
                'validate_cert' => $this->config['validate_cert'],
                'username' => $this->config['username'],
                'password' => $this->config['password'],
                'protocol' => 'imap',
            ]);
            $this->client->connect();
        }

        return $this->client;
    }
}
