<?php
declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use App\Service\Exception\UploadPreflightException;
use App\Service\Exception\LexwareHttpException;

/**
 * Lexware Public API client for voucher file uploads.
 * All comments are in English.
 */
final class LexwareClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,   // @http_client or scoped client
        private readonly FileInspector $inspector,          // preflight validation (size/mime/magic)
        #[Autowire(service: 'monolog.logger.lexware')]
        private readonly LoggerInterface $logger,           // dedicated logger channel
        private readonly string $baseUri,                   // e.g. https://api.lexware.io
        private readonly string $apiKey,                    // Bearer token
        private readonly ?string $tenant = null,            // optional tenant header
        private readonly string $uploadEndpoint = '/v1/files', // correct default endpoint
        private readonly int $maxAttempts = 3,
        private readonly int $baseSleepMs = 250,
    ) {}

    /** Upload a voucher file and return decoded JSON. */
    public function uploadVoucherFile(string $absolutePath): array
    {
        // convert ANY PHP warning/notice into ErrorException as early as possible
        $prevHandler = set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) return false; // keep silenced @-errors silenced
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            // Preflight: validate size/mime/magic
            $check = $this->inspector->validateVoucherUpload($absolutePath);
            if (!$check['ok']) {
                $this->logger->error('Preflight failed', ['path' => $absolutePath, 'reason' => $check['reason'] ?? null]);
                throw new UploadPreflightException('Preflight failed: '.$check['reason']);
            }

            // Ensure a valid filename (extension)
            $filename = basename($absolutePath);
            if (!preg_match('/\.(pdf|png|jpg|jpeg|xml)$/i', $filename)) {
                $ext = match ($check['mime'] ?? '') {
                    'application/pdf'              => '.pdf',
                    'image/png'                    => '.png',
                    'image/jpeg'                   => '.jpg',
                    'application/xml', 'text/xml'  => '.xml',
                    default                        => '',
                };
                $filename .= $ext;
            }

            $mime = $check['mime'] ?? 'application/octet-stream';

            // Build multipart (these calls are now inside the error-handler scope)
            $form = new FormDataPart([
                'type' => 'voucher',
                'file' => DataPart::fromPath($absolutePath, $filename, $mime),
            ]);

            // Build header lines ONLY; never parse/split -> avoids "array key 1"
            $headerLines = $form->getPreparedHeaders()->toArray(); // e.g. ["Content-Type: multipart/form-data; boundary=..."]
            $headerLines[] = 'Accept: application/json';
            if ($this->tenant) {
                $headerLines[] = 'X-Lexware-Tenant: '.$this->tenant;
            }

            // Debug: show the exact header lines (no secrets)
            $this->logger->debug('multipart header lines', ['lines' => $headerLines]);

            $url = rtrim($this->baseUri, '/') . '/' . ltrim($this->uploadEndpoint, '/');

            $attempt = 0;
            start:
            $attempt++;
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'auth_bearer' => $this->apiKey,   // Authorization header handled by client
                    'headers'     => $headerLines,    // ONLY raw header lines
                    'body'        => $form->bodyToIterable(),
                ]);
            } catch (TransportExceptionInterface $te) {
                if ($attempt < $this->maxAttempts) {
                    $sleep = $this->baseSleepMs * (2 ** ($attempt - 1));
                    $this->logger->warning('Transport error on upload, retrying', [
                        'attempt' => $attempt,
                        'sleep_ms' => $sleep,
                        'error' => $te->getMessage(),
                    ]);
                    usleep($sleep * 1000);
                    goto start;
                }
                $this->logger->error('Transport error on upload, giving up', ['attempts' => $attempt, 'error' => $te->getMessage()]);
                throw $te;
            }

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);

            $this->logger->info('Lexware upload response', [
                'url'    => $url,
                'status' => $status,
                'size'   => $check['size'] ?? null,
                'mime'   => $mime,
                'file'   => $absolutePath,
                'attempt'=> $attempt,
            ]);

            if ($status === 406) {
                $this->logger->error('Lexware 406 Not Acceptable', ['response' => $body]);
                throw new LexwareHttpException(406, "Lexware 406 Not Acceptable — likely file type/extension issue or e-invoice not enabled. Response: {$body}");
            }

            if ($status === 409) { // optional duplicate handling
                $this->logger->warning('Lexware 409 Conflict (possible duplicate upload)', ['response' => $body]);
                $json = json_decode($body, true);
                if (is_array($json)) {
                    return $json;
                }
            }

            $isTransient = $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
            if ($isTransient && $attempt < $this->maxAttempts) {
                $sleep = $this->baseSleepMs * (2 ** ($attempt - 1));
                $this->logger->warning('Lexware transient HTTP error, retrying', [
                    'status' => $status,
                    'attempt' => $attempt,
                    'sleep_ms' => $sleep,
                ]);
                usleep($sleep * 1000);
                goto start;
            }

            if ($status < 200 || $status >= 300) {
                $this->logger->error('Lexware upload failed (non-2xx)', ['status' => $status, 'response' => $body]);
                throw new LexwareHttpException($status, "Lexware upload failed: HTTP {$status} — {$body}");
            }

            $json = $response->toArray(false);
            return is_array($json) ? $json : [];
        } catch (\ErrorException $e) {
            // Any PHP warning/notice ends here with full context
            $this->logger->error('PHP warning/notice during upload', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'path'    => $absolutePath,
            ]);
            throw new \RuntimeException($e->getMessage(), previous: $e);
        } finally {
            if ($prevHandler !== null) set_error_handler($prevHandler); else restore_error_handler();
        }
    }
}
