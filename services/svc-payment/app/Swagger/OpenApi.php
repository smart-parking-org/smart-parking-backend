<?php

namespace App\Swagger;

use App\Swagger\Schemas\ParkingLotSchema;
use App\Swagger\Schemas\ParkingSlotSchema;
use App\Swagger\Schemas\PricingRuleSchema;
use App\Swagger\Schemas\PeakHourSchema;
use App\Swagger\Schemas\ValidationErrorSchema;

/**
 * @OA\Info(
 *     title="Smart Parking - Payment Service API",
 *     version="1.0.0",
 *     description="OpenAPI docs cho svc-payment"
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
