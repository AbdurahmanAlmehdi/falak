<?php

namespace Falak\Edge\Infrastructure\Cloudflare;

/**
 * The GraphQL Analytics API answered with `errors` (with HTTP 200 as a rule, or a REST-style 4xx). Cloudflare's
 * messages are kept as they are; whatever data came back with them stays readable in $data.
 */
final class CloudflareGraphqlError extends CloudflareError
{
    /**
     * @param  list<array{message: string, path: list<string|int>, code: ?string}>  $errors
     * @param  array<string, mixed>  $data  partial data returned alongside the errors
     */
    public function __construct(public readonly array $errors, public readonly array $data = [], int $status = 200)
    {
        $messages = array_values(array_unique(array_filter(array_column($errors, 'message'))));

        parent::__construct('Cloudflare: '.($messages === [] ? 'GraphQL error' : implode('; ', $messages)), $status, (int) ($errors[0]['code'] ?? 0));
    }

    /**
     * Whether Cloudflare refused the token access: HTTP 401/403, REST codes 10000 / 9109, GraphQL `authz`, or its
     * "not authorized" wording.
     */
    public function unauthorized(): bool
    {
        if ($this->status === 401 || $this->status === 403) {
            return true;
        }

        foreach ($this->errors as $error) {
            $message = strtolower($error['message']);

            if (in_array($error['code'], ['authz', '10000', '9109'], true) || str_contains($message, 'not authorized') || str_contains($message, 'authentication error')) {
                return true;
            }
        }

        return false;
    }
}
