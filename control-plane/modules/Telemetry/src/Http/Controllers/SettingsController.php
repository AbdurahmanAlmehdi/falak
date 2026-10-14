<?php

namespace Falak\Telemetry\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Telemetry\Application\Actions\ProvisionGrafana;
use Falak\Telemetry\Application\Actions\UpdateTelemetrySettings;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Falak\Telemetry\Domain\Models\GrafanaState;
use Falak\Telemetry\Domain\Models\TelemetrySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SettingsController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function show(Request $request, MetricsBackend $metrics, TelemetryLinks $links): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');

        $settings = TelemetrySettings::for($organizationId);
        $grafana = GrafanaState::query()->find($organizationId);

        return Inertia::render('Telemetry/Settings', [
            'settings' => [
                'otlp_endpoint' => $settings->otlp_endpoint,
                'otlp_token_set' => $settings->otlp_token !== null,
                'environment' => $settings->environment,
                'traces_ratio' => $settings->traces_ratio,
                'metrics_interval_s' => $settings->metrics_interval_s,
                'request_id_linking' => (bool) $settings->request_id_linking,
            ],
            'defaults' => [
                'otlp_endpoint' => (string) config('telemetry.otlp.endpoint'),
                'otlp_token_set' => (string) config('telemetry.otlp.token') !== '',
                'environment' => (string) config('telemetry.defaults.environment'),
                'traces_ratio' => (float) config('telemetry.defaults.traces_ratio'),
                'metrics_interval_s' => (int) config('telemetry.defaults.metrics_interval_s'),
            ],
            'backends' => [
                'metrics' => ['backend' => $metrics->name(), 'configured' => (string) config('telemetry.metrics.query_url') !== ''],
                'loki' => ['configured' => (string) config('telemetry.loki.url') !== ''],
                'tempo' => ['configured' => (string) config('telemetry.tempo.url') !== ''],
                'grafana' => [
                    'configured' => (string) config('telemetry.grafana.url') !== '' && (string) config('telemetry.grafana.token') !== '',
                    'provisioned_at' => $grafana?->provisioned_at?->toIso8601String(),
                    'last_error' => $grafana?->last_error,
                    'dashboards' => collect($grafana?->dashboards ?? [])->map(fn (string $uid, string $base) => [
                        'uid' => $base,
                        'url' => $links->grafanaDashboard($organizationId, $base),
                    ])->values(),
                ],
            ],
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'telemetry.manage')],
        ]);
    }

    public function update(Request $request, UpdateTelemetrySettings $update): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.manage');

        $data = $request->validate([
            'otlp_endpoint' => ['nullable', 'url:http,https', 'max:2048'],
            'otlp_token' => ['nullable', 'string', 'max:1024'],
            'clear_otlp_token' => ['boolean'],
            'environment' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'traces_ratio' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'metrics_interval_s' => ['nullable', 'integer', 'min:5', 'max:3600'],
            'request_id_linking' => ['sometimes', 'boolean'],
        ]);

        $count = $update($organizationId, $data);

        return back()->with('status', "Telemetry settings saved; {$count} server(s) reconfigured.");
    }

    public function provisionGrafana(Request $request, ProvisionGrafana $provision): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.manage');

        try {
            $dashboards = $provision($organizationId);
        } catch (Throwable $e) {
            return back()->withErrors(['grafana' => 'Grafana provisioning failed: '.$e->getMessage()]);
        }

        return back()->with('status', 'Grafana provisioned ('.count($dashboards).' dashboards).');
    }
}
