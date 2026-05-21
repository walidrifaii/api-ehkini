<?php

namespace App\OpenApi\V2;

/**
 * @OA\OpenApi(
 *     openapi="3.0.0",
 *
 *     @OA\Info(
 *         title="Taaruf API v2",
 *         version="2.0.0",
 *         description="REST API documentation for Taaruf mobile app (v2). Base URL prefix: `/api/v2`. Authenticated routes require Laravel Sanctum bearer token."
 *     ),
 *
 *     @OA\Server(
 *         url=L5_SWAGGER_CONST_HOST,
 *         description="Application server (set L5_SWAGGER_CONST_HOST in .env to your APP_URL)"
 *     ),
 *
 *     @OA\Components(
 *         @OA\SecurityScheme(
 *             securityScheme="sanctum",
 *             type="http",
 *             scheme="bearer",
 *             bearerFormat="Sanctum",
 *             description="Login or register to obtain a token. Use: Authorization: Bearer {token}"
 *         )
 *     )
 * )
 */
final class OpenApi
{
}
