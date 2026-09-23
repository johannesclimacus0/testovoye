<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralsController extends Controller
{
    public function __construct(private ReferralService $service)
    {
    }

    public function attach(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        $master = $request->attributes->get('current_master');

        if(!$master){
            return response()->json([
                'error' => 'Мастер не найден'
            ],401);
        }

        $referral = $this->service->registerReferral($master, $data['code']);

        if(!$referral){
            return response()->json([
                'error' => 'Неверный код или привязка к самому себе'
            ], 422);
        }

        return response()->json([
            'success' => [
                'id' => $referral->getKey(),
                'status' => $referral->status,
                'wasCreated' => $referral->wasRecentlyCreated,
            ],
        ],201);
    }

    public function my(Request $request): JsonResponse
    {
        $master = $request->attributes->get('current_master');

        if(!$master){
            return response()->json([
                'error' => 'Мастер не найден'
            ],401);
        }

        $referrals = $master->referrals()
            ->with('referredMaster')
            ->get();

        $rewarded = ReferralEarning::query()
            ->whereIn('referral_id', $referrals->pluck('id'))
            ->selectRaw('referral_id, sum(amount) as total_sum')
            ->groupBy('referral_id')
            ->pluck('total_sum', 'referral_id');

        return response()->json([
            'referrals' => $referrals->map(function ($referral) use ($rewarded) {
                return [
                    'name' => $referral->referredMaster->name,
                    'attachmentDate' => $referral->created_at,
                    'wasRewarded' => $referral->status === Referral::STATUS_REWARDED,
                    'rewardedAmount' => $rewarded[$referral->getKey()] ?? 0
                ];
            }),
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $master = $request->attributes->get('current_master');

        if(!$master){
            return response()->json([
                'error' => 'Мастер не найден'
            ],401);
        }

        $earnings = ReferralEarning::query()
            ->where('referrer_master_id', $master->getKey());

        return response()->json([
            'earnings' => [
                'total' => (clone $earnings)->sum('amount'),
                'pending' => (clone $earnings)
                    ->where('status', ReferralEarning::STATUS_PENDING)
                    ->sum('amount'),
                'rewarded' => (clone $earnings)
                    ->where('status', ReferralEarning::STATUS_PAID),
                'activeReferrals' => $master->referrals()
                    ->active()->count()
            ],
        ]);
    }
}
