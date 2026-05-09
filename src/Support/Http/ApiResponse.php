<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Core\Response;
use App\Support\Pagination;

final class ApiResponse
{
    public static function success(mixed $data = [], string $message = 'Request completed successfully.', array $meta = []): Response
    {
        return Response::success($data, $message, $meta);
    }

    public static function error(int $status, string $code, string $message, array $details = []): Response
    {
        return Response::error($status, $code, $message, $details);
    }

    public static function paginated(array $result, int $page, int $perPage, string $message = 'Items retrieved successfully.'): Response
    {
        return Response::success(
            $result['items'] ?? [],
            $message,
            Pagination::meta($page, $perPage, (int) ($result['total'] ?? 0))
        );
    }

    public static function validationError(array $errors, string $message = 'The given data was invalid.'): Response
    {
        return self::error(422, 'VALIDATION_ERROR', $message, $errors);
    }
}
