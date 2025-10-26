<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="Payment",
 *   type="object",
 *   required={"order_id","amount","txn_ref","status"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="order_id", type="string", example="INV-10001"),
 *   @OA\Property(property="amount", type="integer", example=50000),
 *   @OA\Property(property="txn_ref", type="string", example="ORD20251018093000123"),
 *   @OA\Property(property="status", type="string", enum={"PENDING","PAID","FAILED"}, example="PENDING"),
 *   @OA\Property(property="vnp_response_code", type="string", nullable=true),
 *   @OA\Property(property="vnp_transaction_no", type="string", nullable=true),
 *   @OA\Property(property="bank_code", type="string", nullable=true),
 *   @OA\Property(property="card_type", type="string", nullable=true),
 *   @OA\Property(property="meta", type="object", nullable=true),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'amount',
        'txn_ref',
        'status',
        'vnp_response_code',
        'vnp_transaction_no',
        'bank_code',
        'card_type',
        'meta'
    ];

    protected $casts = [
        'amount' => 'integer',
        'meta' => 'array',
    ];
}
