<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Gifts", description="Virtual gifts and wallet")
 *
 * @OA\Get(
 *     path="/api/v2/gift-categories",
 *     tags={"Gifts"},
 *     summary="List gift categories",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Categories")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/gifts",
 *     tags={"Gifts"},
 *     summary="List gifts",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="category_id", in="query", @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Gifts list")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/gifts/send",
 *     tags={"Gifts"},
 *     summary="Send gift to user",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="gift_id", type="integer"),
 *             @OA\Property(property="receiver_id", type="integer"),
 *             @OA\Property(property="quantity", type="integer", default=1)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Gift sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/wallet/add",
 *     tags={"Gifts"},
 *     summary="Add wallet balance",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="amount", type="number", format="float"),
 *             @OA\Property(property="reference", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Balance updated")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/wallet/balance",
 *     tags={"Gifts"},
 *     summary="Get wallet balance",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Current balance")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/wallet/gift-transactions",
 *     tags={"Gifts"},
 *     summary="Gift transaction history",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Transactions")
 * )
 */
final class GiftWalletPaths
{
}
