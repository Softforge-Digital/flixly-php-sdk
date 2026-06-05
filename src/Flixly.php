<?php

declare(strict_types=1);

namespace Flixly;

/**
 * Official PHP SDK for the Flixly AI API.
 *
 * Targets PHP 7.4+ so the same package works inside the WordPress
 * plugin (which still supports PHP 7.4 per the WordPress.org minimum)
 * and inside modern PHP 8.x backends without modification.
 *
 * Transport: curl extension (always available on production hosts,
 * and the only HTTP client the WP environment can rely on without
 * pulling Guzzle). The class is intentionally dependency-free.
 *
 * Example:
 *
 *     use Flixly\Flixly;
 *     use Flixly\FlixlyError;
 *
 *     $flixly = new Flixly(['api_key' => getenv('FLIXLY_API_KEY')]);
 *
 *     try {
 *         $gen = $flixly->generateAndWait([
 *             'model'  => 'flux-dev',
 *             'prompt' => 'A cat in a top hat, oil painting style',
 *             'type'   => 'TEXT_TO_IMAGE',
 *             'input'  => ['aspect_ratio' => '1:1', 'resolution' => '1K'],
 *         ]);
 *         echo $gen['data']['output_url'];
 *     } catch (FlixlyError $e) {
 *         error_log("Flixly: {$e->getCode()} {$e->getMessage()}");
 *     }
 *
 * Webhook verification (use Flixly::verifyWebhookSignature) is a
 * static method so it can be called from your webhook handler
 * without instantiating the client.
 */
final class Flixly
{
    public const BASE_URL = 'https://www.flixly.ai';
    public const VERSION  = '0.1.0';

    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeoutMs;

    /**
     * @param array{api_key:string, base_url?:string, timeout_ms?:int} $options
     */
    public function __construct(array $options)
    {
        if (empty($options['api_key'])) {
            throw new \InvalidArgumentException('Flixly: "api_key" is required.');
        }
        $this->apiKey   = (string) $options['api_key'];
        $this->baseUrl  = rtrim((string) ($options['base_url'] ?? self::BASE_URL), '/');
        $this->timeoutMs = (int) ($options['timeout_ms'] ?? 120000);
    }

    // ─── Generations ──────────────────────────────────────────────

    /**
     * Submit a generation. Returns immediately with status "processing"
     * for async models, or "completed" for fast sync models.
     *
     * @param array<string,mixed> $req
     * @return array{data: array<string,mixed>, rateLimit: ?array<string,int>}
     */
    public function generate(array $req): array
    {
        return $this->request('POST', '/api/v1/generate', $req);
    }

    /**
     * @return array{data: array<string,mixed>, rateLimit: ?array<string,int>}
     */
    public function getGeneration(string $id): array
    {
        return $this->request('GET', '/api/v1/generations/' . rawurlencode($id));
    }

    /**
     * Submit a generation and poll until it completes or fails.
     * Useful for one-shot scripts; prefer webhook_url in production.
     *
     * @param array<string,mixed> $req
     * @param array{poll_interval_ms?:int, max_wait_ms?:int} $opts
     * @return array{data: array<string,mixed>, rateLimit: ?array<string,int>}
     */
    public function generateAndWait(array $req, array $opts = []): array
    {
        $interval = (int) ($opts['poll_interval_ms'] ?? 2000);
        $maxWait  = (int) ($opts['max_wait_ms'] ?? 10 * 60000);
        $start    = (int) round(microtime(true) * 1000);

        $initial = $this->generate($req);
        $status  = $initial['data']['status'] ?? '';
        if ($status === 'completed' || $status === 'failed') {
            return $initial;
        }

        $last = $initial;
        $id   = (string) ($initial['data']['id'] ?? '');
        while (((int) round(microtime(true) * 1000)) - $start < $maxWait) {
            usleep($interval * 1000);
            $last = $this->getGeneration($id);
            $status = $last['data']['status'] ?? '';
            if ($status === 'completed' || $status === 'failed') {
                return $last;
            }
        }
        throw new FlixlyError(
            408,
            'timeout',
            sprintf(
                'Generation %s did not finish within %ds. Last status: %s.',
                $id,
                (int) round($maxWait / 1000),
                (string) ($last['data']['status'] ?? 'unknown')
            ),
            null,
            $last['rateLimit']
        );
    }

    // ─── Models ────────────────────────────────────────────────────

    /** @return array{data: array<string,mixed>, rateLimit: ?array<string,int>} */
    public function listModels(): array
    {
        return $this->request('GET', '/api/v1/models');
    }

    // ─── Chat ──────────────────────────────────────────────────────

    /**
     * OpenAI-compatible non-streaming chat completion.
     *
     * @param array<string,mixed> $req
     * @return array{data: array<string,mixed>, rateLimit: ?array<string,int>}
     */
    public function chat(array $req): array
    {
        $req['stream'] = false;
        return $this->request('POST', '/api/v1/chat/completions', $req);
    }

    // (Streaming chat is intentionally omitted from the PHP SDK for
    // now — WP plugins are the primary consumer and they don't run
    // long-lived streaming connections inside a request handler.)

    // ─── Account ───────────────────────────────────────────────────

    /** @return array{data: array<string,mixed>, rateLimit: ?array<string,int>} */
    public function getAccount(): array
    {
        return $this->request('GET', '/api/v1/account');
    }

    // ─── Webhook verification (static) ─────────────────────────────

    /**
     * Verify a Flixly webhook delivery. Returns true if and only if
     * the signature is valid AND the timestamp is within `tolerance`
     * seconds (default 300, i.e. 5 minutes — Stripe-style replay
     * protection).
     *
     * Call this on EVERY webhook POST you receive. Pass the raw
     * request body verbatim — re-encoding the JSON will break
     * verification.
     */
    public static function verifyWebhookSignature(
        string $secret,
        string $timestamp,
        string $signature,
        string $body,
        int $toleranceSeconds = 300
    ): bool {
        if (strpos($signature, 'sha256=') !== 0) {
            return false;
        }
        $provided = strtolower(substr($signature, 7));
        $ts = (int) $timestamp;
        if ($ts <= 0) {
            return false;
        }
        if (abs(time() - $ts) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        return hash_equals($expected, $provided);
    }

    // ─── Internals ─────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|null $body
     * @return array{data: array<string,mixed>, rateLimit: ?array<string,int>}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);
        if ($ch === false) {
            throw new FlixlyError(0, 'internal_error', 'curl_init failed', null, null);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: flixly-php/' . self::VERSION,
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => 10000,
            CURLOPT_FOLLOWLOCATION => false,  // we explicitly use www.flixly.ai
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);

        /** @var string|false $raw */
        $raw = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $errNo !== 0) {
            throw new FlixlyError(
                0,
                'network_error',
                'Flixly request failed: ' . ($errMsg ?: 'unknown') . ' (curl errno ' . $errNo . ')',
                null,
                null
            );
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody    = substr($raw, $headerSize);
        $rateLimit  = self::parseRateLimit($rawHeaders);

        $decoded = json_decode($rawBody, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new FlixlyError($status, 'internal_error', 'Invalid JSON in response', null, $rateLimit);
        }

        if ($status < 200 || $status >= 300) {
            $err = is_array($decoded) ? ($decoded['error'] ?? []) : [];
            throw new FlixlyError(
                $status,
                (string) ($err['code'] ?? 'internal_error'),
                (string) ($err['message'] ?? ('HTTP ' . $status)),
                is_array($err['details'] ?? null) ? $err['details'] : null,
                $rateLimit
            );
        }

        return [
            'data'      => is_array($decoded) ? $decoded : [],
            'rateLimit' => $rateLimit,
        ];
    }

    /**
     * @return ?array{limit:int, remaining:int, resetAtSec:int}
     */
    private static function parseRateLimit(string $rawHeaders): ?array
    {
        $limit = null;
        $remaining = null;
        $reset = null;
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $val  = trim(substr($line, $pos + 1));
            if ($name === 'x-ratelimit-limit')     $limit = (int) $val;
            if ($name === 'x-ratelimit-remaining') $remaining = (int) $val;
            if ($name === 'x-ratelimit-reset')     $reset = (int) $val;
        }
        if ($limit === null || $remaining === null || $reset === null) {
            return null;
        }
        return ['limit' => $limit, 'remaining' => $remaining, 'resetAtSec' => $reset];
    }
}
