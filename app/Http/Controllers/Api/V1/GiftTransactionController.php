<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftTransaction;
use Illuminate\Http\Request;

class GiftTransactionController extends Controller
{
    /**
     * GET /api/v1/wallet/gift-transactions
     * Query:
     *  type=all|sent|received
     *  per_page=10
     */
    public function index(Request $request)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'type' => ['nullable', 'in:all,sent,received'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $type = $data['type'] ?? 'all';
        $perPage = 5;

        $q = GiftTransaction::query()
            ->with([
                'gift:id,name,image,price', // ✅ adjust gift columns if needed
                'sender:id,first_name,last_name,profile_image',
                'receiver:id,first_name,last_name,profile_image',
            ])
            ->where(function ($qq) use ($me) {
                $qq->where('sender_id', $me->id)
                   ->orWhere('receiver_id', $me->id);
            });

        if ($type === 'sent') {
            $q->where('sender_id', $me->id);
        } elseif ($type === 'received') {
            $q->where('receiver_id', $me->id);
        }

        $items = $q->orderByDesc('id')->paginate($perPage);

        // ✅ clean response
        $items->getCollection()->transform(function ($t) use ($me) {

            $direction = ((int)$t->sender_id === (int)$me->id) ? 'sent' : 'received';
            $other = ($direction === 'sent') ? $t->receiver : $t->sender;

            return [
                'id' => $t->id,
                'direction' => $direction,           // sent / received
                'status' => $t->status,              // sent / completed (from your table)
                'price' => (string) $t->price,
                'created_at' => $t->created_at,

                'gift' => $t->gift ? [
                    'id' => $t->gift->id,
                    'name' => $t->gift->name,
                    'image' => $t->gift->image,
                    'image_url' => $t->gift->image_url ?? null, // if you have accessor
                    'price' => (string) $t->gift->price,
                ] : null,

                'other_user' => $other ? [
                    'id' => $other->id,
                    'first_name' => $other->first_name,
                    'last_name' => $other->last_name,
                    'profile_image' => $other->profile_image,
                    'profile_image_url' => $other->profile_image_url ?? null,
                ] : null,
            ];
        });

        return response()->json($items);
    }
}