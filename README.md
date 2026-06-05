# flixly/sdk (PHP)

Official PHP SDK for the [Flixly](https://www.flixly.ai) AI API. Targets PHP 7.4+ so it drops cleanly into WordPress plugins and modern PHP backends.

## Install

```bash
composer require flixly/sdk
```

Requirements:
- PHP 7.4 or newer
- `ext-curl`, `ext-json`, `ext-hash` (all standard)

No other dependencies. Drop-in compatible with WordPress.

## Quick start

```php
use Flixly\Flixly;
use Flixly\FlixlyError;

$flixly = new Flixly(['api_key' => getenv('FLIXLY_API_KEY')]);

try {
    $gen = $flixly->generateAndWait([
        'model'  => 'flux-dev',
        'prompt' => 'A cat in a top hat, oil painting style',
        'type'   => 'TEXT_TO_IMAGE',
        'input'  => ['aspect_ratio' => '1:1', 'resolution' => '1K'],
    ]);
    echo $gen['data']['output_url']; // cdn.flixly.ai URL
} catch (FlixlyError $e) {
    error_log("Flixly: {$e->getErrorCode()} {$e->getMessage()}");
}
```

Get an API key at [www.flixly.ai/dashboard/settings/api-keys](https://www.flixly.ai/dashboard/settings/api-keys).

## Webhooks (recommended for video / slow models)

```php
$flixly->generate([
    'model'        => 'veo-3-fast',
    'prompt'       => 'Cinematic shot of mountains at dawn',
    'type'         => 'TEXT_TO_VIDEO',
    'webhook_url'  => 'https://example.com/flixly-webhook',
]);
```

In your webhook handler:

```php
$body      = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FLIXLY_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_FLIXLY_TIMESTAMP'] ?? '';

$valid = Flixly::verifyWebhookSignature(
    getenv('FLIXLY_WEBHOOK_SECRET'),
    $timestamp,
    $signature,
    $body  // raw body — do NOT json_decode + re-encode, that breaks the signature
);

if (!$valid) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true);
// $event['event']       => "generation.completed" or "generation.failed"
// $event['id']          => task id
// $event['output_url']  => cdn.flixly.ai URL on success
```

The webhook secret is shown once when you create the API key. Store it on your server like any other secret.

## Rate limits

Every successful response includes parsed rate-limit info:

```php
$res = $flixly->listModels();
print_r($res['rateLimit']);
// ['limit' => 60, 'remaining' => 42, 'resetAtSec' => 1735056000]
```

When a `FlixlyError` is thrown, the rate-limit info is on the exception:

```php
try {
    $flixly->generate(['model' => 'flux-dev', 'prompt' => 'hi']);
} catch (FlixlyError $e) {
    $rl = $e->getRateLimit();
    if ($e->getHttpStatus() === 429 && $rl !== null) {
        $wait = max(0, $rl['resetAtSec'] - time());
        sleep($wait);
        // retry
    }
}
```

## Chat (OpenAI-compatible)

```php
$res = $flixly->chat([
    'model'    => 'gpt-5-4-mini',
    'messages' => [
        ['role' => 'user', 'content' => 'Explain async/await in one sentence.'],
    ],
]);

echo $res['data']['choices'][0]['message']['content'];
```

Streaming chat is omitted from the PHP SDK by design — most PHP consumers (WordPress plugins, REST endpoints) don't run long-lived streaming inside a request handler. Use the JS SDK or raw curl if you need SSE.

## Configuration

```php
new Flixly([
    'api_key'    => 'flx_live_...',
    'base_url'   => 'https://www.flixly.ai',  // default
    'timeout_ms' => 120000,                   // default 2 minutes
]);
```

## License

MIT
