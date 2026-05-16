<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MaestroBootstrap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(MaestroBootstrap::build($request));
    }
}
