<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalizeApiJsonResponse
{
    /** JSON keys whose string values are user-facing API messages. */
    private const MESSAGE_KEYS = ['message', 'error', 'title'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*') || ! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }

        $response->setData($this->localize($data));

        return $response;
    }

    private function localize(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            if ($key === 'errors' && is_array($value)) {
                $payload[$key] = $this->localizeValidationErrors($value);
                continue;
            }

            if (in_array($key, self::MESSAGE_KEYS, true) && is_string($value)) {
                $payload[$key] = api_translate_message($value);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->localize($value);
            }
        }

        return $payload;
    }

    private function localizeValidationErrors(array $errors): array
    {
        foreach ($errors as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_map(
                static fn ($msg) => is_string($msg) ? api_translate_message($msg) : $msg,
                $messages
            );
        }

        return $errors;
    }
}
