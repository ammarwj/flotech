<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Consistent success/error envelope for the flo-event API.
 *
 * Success: { "success": true,  "message": ..., "data": ... }
 * Error:   { "success": false, "message": ..., "errors": ... }
 */
class ApiResponse
{
    /**
     * @param  mixed  $data
     */
    public static function success($data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(string $message = 'Error', ?array $errors = null, int $status = 400): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
