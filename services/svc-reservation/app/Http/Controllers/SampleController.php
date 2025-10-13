<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SampleController extends Controller
{
    /**
     * @OA\Tag(
     *   name="Sample",
     *   description="Sample endpoints"
     * )
     */

    /**
     * Ping healthcheck
     *
     * @OA\Get(
     *   path="/reservation/ping",
     *   summary="Ping",
     *   tags={"Sample"},
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="pong")
     *     )
     *   )
     * )
     */
    public function ping()
    {
        return response()->json(['message' => 'pong']);
    }
}
