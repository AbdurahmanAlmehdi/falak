<?php

namespace Falak\Network\Application\Jobs;

use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Network\Application\Queries\EbpfQueries;
use Falak\Network\Domain\Models\EbpfSetting;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\MetricsBackend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * DNS failure and TCP retransmit spikes (docs/plans/network/ebpf.md, "Alerts"), every minute, per server and workload,
 * from the eBPF metrics of the servers with the setting on. Conditions of workloads (or servers) no longer reporting
 * are cleared.
 */
final class CheckEbpfAlerts implements ShouldQueue
{
    use Queueable;

    public const ALERT_DNS = 'network.dns_failures';

    public const ALERT_RETRANSMITS = 'network.tcp_retransmits';

    public function handle(MetricsBackend $metrics, AlertConditions $conditions, ServerDirectory $servers): void
    {
        $settings = EbpfSetting::query()->get()->keyBy('server_id');

        if ($settings->isEmpty() || (string) config('telemetry.metrics.query_url') === '') {
            return;
        }

        try {
            $rows = [];

            foreach (EbpfQueries::alerts() as $key => $promql) {
                foreach ($metrics->query($promql) as $series) {
                    $server = strtolower($series->labels['falak_server_id'] ?? '');
                    $workload = ($series->labels['service_name'] ?? 'falak-host').'/'.($series->labels['falak_workload_name'] ?? 'host');
                    $rows[$server][$workload][$key] = (float) $series->last();
                }
            }
        } catch (TelemetryUnavailable|TelemetryQueryFailed) {
            return;
        }

        $forSeconds = (int) config('network.ebpf.alert_seconds', 300);
        $dnsMin = (float) config('network.ebpf.dns_failures_min', 60);
        $dnsRatio = (float) config('network.ebpf.dns_failure_ratio', 0.2);
        $retransmitsMin = (float) config('network.ebpf.retransmits_min', 600);

        foreach ($settings as $serverId => $setting) {
            $server = $setting->enabled ? $servers->find($serverId) : null;
            $keys = ['dns' => [], 'tcp' => []];

            foreach ($server !== null ? ($rows[$serverId] ?? []) : [] as $workload => $v) {
                $label = str_starts_with($workload, 'falak-host/') ? 'the server' : $workload;
                $failures = $v['dns_failures'] ?? 0.0;
                $queries = $v['dns_queries'] ?? 0.0;

                if ($queries > 0 || $failures > 0) {
                    $key = self::ALERT_DNS.":{$serverId}:{$workload}";
                    $keys['dns'][] = $key;
                    $percent = $queries > 0 ? round($failures * 100 / $queries) : 100;
                    $conditions->observe($setting->organization_id, $key, $failures >= $dnsMin && ($queries <= 0 || $failures / $queries >= $dnsRatio), fn () => new AlertData(
                        $setting->organization_id, self::ALERT_DNS, Severity::Warning,
                        "{$server->name}: DNS lookups failing for {$label} ({$percent}% of queries)",
                        sprintf('%d of %d DNS queries in 5 minutes failed (SERVFAIL, REFUSED or no answer). Check the resolver (/etc/resolv.conf, systemd-resolved) and the names in the Network tab.', (int) $failures, (int) $queries),
                        "/servers/{$serverId}/network", context: ['server_id' => $serverId, 'workload' => $workload, 'failures' => (int) $failures, 'queries' => (int) $queries],
                        action: 'Review DNS',
                    ), forSeconds: $forSeconds);
                }

                if (isset($v['retransmits'])) {
                    $key = self::ALERT_RETRANSMITS.":{$serverId}:{$workload}";
                    $keys['tcp'][] = $key;
                    $retransmits = (int) $v['retransmits'];
                    $conditions->observe($setting->organization_id, $key, $v['retransmits'] >= $retransmitsMin, fn () => new AlertData(
                        $setting->organization_id, self::ALERT_RETRANSMITS, Severity::Warning,
                        "{$server->name}: TCP retransmits spiking for {$label}",
                        "{$retransmits} TCP segments were retransmitted in 5 minutes: packet loss or congestion on the path to the peers shown in the Network tab.",
                        "/servers/{$serverId}/network", context: ['server_id' => $serverId, 'workload' => $workload, 'retransmits' => $retransmits],
                        action: 'Review TCP health',
                    ), forSeconds: $forSeconds);
                }
            }

            $conditions->clearExcept($setting->organization_id, self::ALERT_DNS.":{$serverId}:", $keys['dns']);
            $conditions->clearExcept($setting->organization_id, self::ALERT_RETRANSMITS.":{$serverId}:", $keys['tcp']);
        }
    }
}
