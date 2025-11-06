<?php
declare(strict_types=1);

namespace App\Imap;

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

    /**
     * @throws RuntimeException
     * @throws ResponseException
     * @throws ImapBadRequestException
     * @throws ConnectionFailedException
     * @throws ImapServerErrorException
     * @throws AuthFailedException
     * @throws MaskNotFoundException
     */
    public function create(): Client
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
