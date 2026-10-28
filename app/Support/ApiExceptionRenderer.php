<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One error envelope for every /api/* response:
 *   { "message": string, "errors"?: {field: [..]}, "request_id": string }
 * Internals (SQL, paths, traces) are never included.
 */
class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return self::render($e, $request);
        });
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        // Unmatched routes fail before the AssignRequestId middleware runs.
        $requestId = $request->attributes->get('request_id') ?? (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        if ($e instanceof ValidationException) {
            return self::json($requestId, 'The given data was invalid.', $e->status, ['errors' => $e->errors()]);
        }

        if ($e instanceof AuthenticationException) {
            return self::json($requestId, 'Unauthenticated.', 401);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return self::json($requestId, 'Resource not found.', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = match (true) {
                $status === 429 => 'Too many requests. Please try again later.',
                $status === 405 => 'Method not allowed.',
                $status === 403 => 'This action is unauthorized.',
                $status === 400 => 'Bad request.',
                $status < 500 => 'Request could not be processed.',
                default => 'Something went wrong on our side. Please try again later.',
            };

            return self::json($requestId, $message, $status, [], $e->getHeaders());
        }

        // Unexpected failure: log with correlation id, answer generically.
        report($e);

        return self::json($requestId, 'Something went wrong on our side. Please try again later.', 500);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    private static function json(string $requestId, string $message, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['message' => $message, ...$extra, 'request_id' => $requestId],
            $status,
            [...$headers, 'X-Request-Id' => $requestId]
        );
    }
}
