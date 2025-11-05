<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\FileInspectorInterface;
use App\Service\Exception\LexwareHttpException;
use App\Service\Exception\UploadPreflightException;
use App\Service\LexwareClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class LexwareClientTest extends TestCase
{
    private function tempPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lx_');
        if ($path === false) self::fail('tempnam failed');
        file_put_contents($path, "%PDF-1.4\nbody");
        return $path;
    }

    private function makeClient(
        HttpClientInterface $http,
        FileInspectorInterface $inspector,
        LoggerInterface $logger,
        int $maxAttempts = 3,
        int $baseSleepMs = 0
    ): LexwareClient {
        return new LexwareClient(
            httpClient: $http,
            inspector: $inspector,
            logger: $logger,
            baseUri: 'https://api.example.test',
            apiKey: 'token',
            tenant: 'tenant-1',
            uploadEndpoint: '/v1/files',
            maxAttempts: $maxAttempts,
            baseSleepMs: $baseSleepMs,
        );
    }

    public function testPreflightFailureThrowsUploadPreflightException(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $inspector = $this->createMock(FileInspectorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $inspector->method('validateVoucherUpload')->willReturn([
            'ok' => false,
            'reason' => 'file_too_large',
            'size' => 999999,
        ]);

        $client = $this->makeClient($http, $inspector, $logger);
        $this->expectException(UploadPreflightException::class);
        $client->uploadVoucherFile('/tmp/path.pdf');
    }

    public function testSuccess200ReturnsJson(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $resp = $this->createMock(ResponseInterface::class);
        $inspector = $this->createMock(FileInspectorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $inspector->method('validateVoucherUpload')->willReturn([
            'ok' => true,
            'mime' => 'application/pdf',
            'size' => 10,
        ]);

        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getContent')->with(false)->willReturn('{"id":"F123","voucherId":"V999"}');
        $resp->method('toArray')->with(false)->willReturn(['id' => 'F123', 'voucherId' => 'V999']);

        $http->method('request')->willReturn($resp);

        $client = $this->makeClient($http, $inspector, $logger);
        $file = $this->tempPdf();
        try {
            $json = $client->uploadVoucherFile($file);
            self::assertSame('F123', $json['id']);
            self::assertSame('V999', $json['voucherId']);
        } finally { @unlink($file); }
    }

    public function testConflict409ReturnsJson(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $resp = $this->createMock(ResponseInterface::class);
        $inspector = $this->createMock(FileInspectorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);
        $resp->method('getStatusCode')->willReturn(409);
        $resp->method('getContent')->with(false)->willReturn('{"id":"dup"}');

        $http->method('request')->willReturn($resp);

        $client = $this->makeClient($http, $inspector, $logger);
        $file = $this->tempPdf();
        try {
            $json = $client->uploadVoucherFile($file);
            self::assertSame('dup', $json['id']);
        } finally { @unlink($file); }
    }

    public function testNotAcceptable406ThrowsLexwareHttpException(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $resp = $this->createMock(ResponseInterface::class);
        $inspector = $this->createMock(FileInspectorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);
        $resp->method('getStatusCode')->willReturn(406);
        $resp->method('getContent')->with(false)->willReturn('Not acceptable');

        $http->method('request')->willReturn($resp);

        $client = $this->makeClient($http, $inspector, $logger, 1);
        $file = $this->tempPdf();
        try {
            $this->expectException(LexwareHttpException::class);
            $client->uploadVoucherFile($file);
        } finally { @unlink($file); }
    }

    public function testTransportExceptionRetriesThenFails(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $inspector = $this->createMock(FileInspectorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);

        $http->method('request')->willReturnCallback(function() {
            static $i = 0; $i++;
            if ($i < 3) {
                $ex = $this->createMock(TransportExceptionInterface::class);
                $ex->method('getMessage')->willReturn('network down');
                throw $ex;
            }
            $resp = $this->createMock(ResponseInterface::class);
            $resp->method('getStatusCode')->willReturn(200);
            $resp->method('getContent')->with(false)->willReturn('{"id":"ok"}');
            $resp->method('toArray')->with(false)->willReturn(['id' => 'ok']);
            return $resp;
        });

        $client = $this->makeClient($http, $inspector, $logger, maxAttempts: 3, baseSleepMs: 0);
        $file = $this->tempPdf();
        try {
            $json = $client->uploadVoucherFile($file);
            self::assertSame('ok', $json['id']);
        } finally { @unlink($file); }
    }

    public function testTransientHttpRetriesThenThrows(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $inspector = $this->createMock(FileInspectorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $resp = $this->createMock(ResponseInterface::class);

        $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);
        $resp->method('getStatusCode')->willReturn(500);
        $resp->method('getContent')->with(false)->willReturn('oops');

        $http->method('request')->willReturn($resp);

        $client = $this->makeClient($http, $inspector, $logger, maxAttempts: 2, baseSleepMs: 0);
        $file = $this->tempPdf();
        try {
            $this->expectException(LexwareHttpException::class);
            $client->uploadVoucherFile($file);
        } finally { @unlink($file); }
    }
}
