<?php

namespace App\Swagger;

/**
 * @OA\Info(
 *     title="Smart Parking - Auth Service API",
 *     version="1.0.0",
 *     description="OpenAPI docs cho svc-auth (JWT auth, refresh, logout, ...)"
 * )
 *
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="Local API through svc-auth"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class OpenApi
{
} // Chỉ để giữ PHPDoc, không cần code gì thêm
