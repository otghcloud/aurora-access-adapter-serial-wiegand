@extends('layouts.admin')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Serial Devices</h1>
            <p class="text-muted mb-0">Live serial reader monitor status, device accessibility, and recent reader activity.</p>
        </div>
        <form method="GET" action="{{ route('admin.serial-devices') }}" class="d-flex flex-wrap align-items-center gap-2">
            <label for="auto_refresh" class="form-label mb-0 small text-muted">Auto-refresh</label>
            <select name="auto_refresh" id="auto_refresh" class="form-select form-select-sm" style="min-width: 170px;">
                @foreach ($autoRefreshOptions as $interval)
                    <option value="{{ $interval }}" @selected($autoRefreshSeconds === $interval)>
                        {{ $interval === 0 ? 'Off' : 'Every '.$interval.'s' }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Refresh</button>
            <a href="{{ route('admin.health') }}" class="btn btn-outline-secondary btn-sm">Back to Health</a>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Generated</p>
                    <p class="mb-0 fw-semibold">{{ \Illuminate\Support\Carbon::parse($diagnostics['generated_at'])->format('d/m/Y H:i:s') }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Configured Readers</p>
                    <p class="display-6 mb-0">{{ $diagnostics['readers_total'] ?? 0 }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Running Monitors</p>
                    <p class="display-6 mb-0">{{ $diagnostics['running_monitors'] ?? 0 }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Readable Devices</p>
                    <p class="display-6 mb-0">{{ $diagnostics['readable_devices'] ?? 0 }}</p>
                    <p class="small text-muted mb-0">processes={{ $diagnostics['command_processes'] ?? 0 }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Reader Status</h2>
            <span class="badge text-bg-secondary">{{ count($diagnostics['readers'] ?? []) }} readers</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Reader</th>
                        <th>Process</th>
                        <th>Supervisor</th>
                        <th>Device</th>
                        <th>Serial Config</th>
                        <th>Latest Event</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($diagnostics['readers'] ?? []) as $reader)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $reader['name'] ?: $reader['identifier'] }}</div>
                                <div class="small text-muted">{{ $reader['identifier'] }}@if(! empty($reader['area_name'])) | {{ $reader['area_name'] }}@endif</div>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ ($reader['process_running'] ?? false) ? 'success' : 'danger' }}">
                                    {{ ($reader['process_running'] ?? false) ? 'running' : 'not running' }}
                                </span>
                                <div class="small text-muted mt-1">matches={{ $reader['process_matches'] ?? 0 }}</div>
                            </td>
                            <td>
                                @php
                                    $supervisorBadge = match ($reader['supervisor_status'] ?? 'unknown') {
                                        'running' => 'success',
                                        'backoff' => 'warning',
                                        'stopped', 'fatal' => 'danger',
                                        default => 'secondary',
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $supervisorBadge }}">{{ $reader['supervisor_status'] ?? 'unknown' }}</span>
                                <div class="small text-muted mt-1">{{ $reader['supervisor_program'] ?? 'n/a' }}</div>
                            </td>
                            <td>
                                <div><code>{{ $reader['device'] ?? '/dev/'.$reader['identifier'] }}</code></div>
                                <div class="small text-{{ ($reader['device_readable'] ?? false) ? 'success' : 'danger' }} mt-1">
                                    {{ ($reader['device_readable'] ?? false) ? 'readable' : 'not readable' }}
                                </div>
                            </td>
                            <td class="small">
                                <div>mode={{ $reader['reader_mode'] ?? 'card_only' }}</div>
                                <div>baud={{ $reader['baud_rate'] ?? 9600 }} timeout={{ $reader['timeout'] ?? 1 }}s</div>
                                <div>dup={{ $reader['duplicate_window'] ?? 2 }}s keypad={{ $reader['keypad_timeout'] ?? 3 }}s</div>
                                <div>card_min={{ $reader['card_min_value'] ?? 15 }} doorbell={{ $reader['doorbell_value'] ?? 11 }}</div>
                            </td>
                            <td>
                                <div>{{ $reader['latest_event_status'] ?? 'No events yet' }}</div>
                                <div class="small text-muted">{{ ! empty($reader['latest_event_at']) ? \Illuminate\Support\Carbon::parse($reader['latest_event_at'])->format('d/m/Y H:i:s') : '-' }}</div>
                                @if (! empty($reader['latest_event_reason']))
                                    <div class="small text-muted">{{ $reader['latest_event_reason'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No access readers configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($autoRefreshSeconds > 0)
        <script>
            window.setTimeout(function () {
                window.location.reload();
            }, {{ $autoRefreshSeconds * 1000 }});
        </script>
    @endif
@endsection
