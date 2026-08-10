<?php

declare(strict_types=1);

namespace OTGH\AccessControl\SerialWiegandAdapter\Http\Controllers\Admin\Health;

use App\Http\Controllers\Controller;
use App\Services\AccessControl\SerialReaderDiagnosticsServiceInterface;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SerialDevicesController extends Controller
{
    public function __invoke(Request $request, SerialReaderDiagnosticsServiceInterface $diagnosticsService): View
    {
        $requestedAutoRefresh = (int) $request->integer('auto_refresh', 0);
        $allowedAutoRefreshIntervals = [0, 5, 10, 30];
        $autoRefreshSeconds = in_array($requestedAutoRefresh, $allowedAutoRefreshIntervals, true)
            ? $requestedAutoRefresh
            : 0;

        return view('serial-wiegand-adapter::admin.health.serial-devices', [
            'diagnostics' => $diagnosticsService->buildPayload(),
            'autoRefreshSeconds' => $autoRefreshSeconds,
            'autoRefreshOptions' => $allowedAutoRefreshIntervals,
        ]);
    }
}
