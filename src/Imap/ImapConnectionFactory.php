<?php
declare(strict_types=1);

namespace App\Imap;

use Webklex\PHPIMAP\ClientManager;

/**
 * Factory that creates standalone Webklex IMAP clients.
 */
final class ImapConnectionFactory
{
    public function __construct(
        private readonly ClientManager $clientManager,
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $encryption, // 'ssl' | 'tls' | null
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function create(): \Webklex\PHPIMAP\Client
    {
        $client = $this->clientManager->make([
            'host'          => $this->host,
            'port'          => $this->port,
            'encryption'    => $this->encryption ?: null,
            'validate_cert' => true,
            'username'      => $this->username,
            'password'      => $this->password,
            'protocol'      => 'imap',
        ]);
        $client->connect();
        return $client;
    }
}
