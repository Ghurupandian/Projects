<?php

namespace App\Http\Controllers\Api;

use App\Actions\GetMerchantDashboardAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantDashboardResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantDashboardController extends Controller
{
    public function show(Request $request, int $id, GetMerchantDashboardAction $dashboard): MerchantDashboardResource|Response
    {
        $merchant = $request->attributes->get('merchant');

        if ($merchant === null || (int) $merchant->id !== $id) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'The requested merchant does not match the authenticated merchant.',
            ], Response::HTTP_FORBIDDEN);
        }

        MerchantDashboardResource::withoutWrapping();

        return new MerchantDashboardResource($dashboard->execute($id));
    }
}
