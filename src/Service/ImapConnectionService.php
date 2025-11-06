<?php

declare(strict_types=1);

namespace App\Service;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

/**
 * Manages IMAP connections and client creation.
 */
final readonly class ImapConnectionService
{
    public function __construct(
        private ClientManager $clientManager,
        private string $imapHost,
        private int $imapPort,
        private ?string $imapEncryption,
        private string $imapUsername,
        private string $imapPassword,
    ) {
    }

    /**
     * @throws RuntimeException
     * @throws ResponseException
     * @throws ImapBadRequestException
     * @throws ConnectionFailedException
     * @throws ImapServerErrorException
     * @throws AuthFailedException
     * @throws MaskNotFoundException
     */
    public function createClient(): Client
    {
        $client = $this->clientManager->make([
            'host' => $this->imapHost,
            'port' => $this->imapPort,
            'encryption' => $this->imapEncryption ?: null,
            'validate_cert' => true,
            'username' => $this->imapUsername,
            'password' => $this->imapPassword,
            'protocol' => 'imap',
        ]);
        $client->connect();

        return $client;
    }

    public function getConnectionParams(): array
    {
        return [
            'host' => $this->imapHost,
            'port' => $this->imapPort,
            'encryption' => $this->imapEncryption,
            'username' => $this->imapUsername,
            'password' => $this->imapPassword,
        ];
    }
}
