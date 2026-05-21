<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    /**
     * POST /api/v1/wallet/add
     * body: { "amount": 50 }
     */
    public function addBalance(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $amount = (float) $data['amount'];

        try {
            DB::beginTransaction();

            // 🔒 Lock wallet row
            $wallet = UserWallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = UserWallet::create([
                    'user_id' => $user->id,
                    'balance' => 0
                ]);
            }

            // ✅ Add balance
            $wallet->balance = (float) $wallet->balance + $amount;
            $wallet->save();

            DB::commit();

            return response()->json([
                'message' => 'Balance added successfully.',
                'amount_added' => number_format($amount, 2),
                'new_balance' => number_format($wallet->balance, 2),
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('WALLET_ADD_ERROR', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to add balance.',
            ], 500);
        }
    }
    
    
    public function balance(Request $request)
{
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $wallet = \App\Models\UserWallet::firstOrCreate(
        ['user_id' => $user->id],
        ['balance' => 0]
    );

    return response()->json([
        'balance' => number_format($wallet->balance, 2),
    ]);
}
}