<?php

namespace Falak\Alerting\Tests\Feature;

use DateTimeImmutable;
use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Enums\AlertOutcome;
use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Enums\DeliveryStatus;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\DedupState;
use Falak\Alerting\Domain\Models\Delivery;
use Falak\Alerting\Domain\Models\Notification;
use Falak\Alerting\Events\NotificationCreated;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentWentOffline;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Events\HeartbeatMissed;
use Falak\Insights\Events\IssueRegressed;
use Falak\Insights\Events\IssueResolved;
use Falak\Insights\Events\ThresholdBreached;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

final class DeploymentFailedForTest implements Alertable
{
    public function __construct(public string $organizationId, public string $deploymentId) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, 'deployments.failed', Severity::Critical, 'Deploy failed', 'Health check timed out', '/deployments/'.$this->deploymentId, "deployment:{$this->deploymentId}");
    }
}

beforeEach(function () {
    alerting_guard();
    Http::preventStrayRequests();
    $this->slackResponse = ['ok', 200];
    Http::fake(fn () => Http::response(...$this->slackResponse));
    [$this->owner, $this->organization] = memberOf();
    alerting_without_default_pack($this->organization->id);
    $this->slack = alerting_channel($this->organization->id);
});

function slack_posts(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'hooks.slack.com'))->count();
}

it('routes a mapped Insights event to matching channels and records history', function () {
    alerting_rule($this->organization->id, ['insights.*'], [$this->slack]);

    event(alerting_issue_opened($this->organization->id));

    $alert = Alert::query()->sole();
    expect($alert->type)->toBe('insights.issue_opened')
        ->and($alert->outcome)->toBe(AlertOutcome::Delivered)
        ->and($alert->severity)->toBe(Severity::Warning)
        ->and($alert->url)->toBe(url('/insights/issues/01JISSUE000000000000000001'))
        ->and($alert->dedup_key)->toBe('insights.issue:01JISSUE000000000000000001')
        ->and(Delivery::query()->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and($this->slack->refresh()->last_sent_at)->not->toBeNull()
        ->and(slack_posts())->toBe(1);
});

it('maps urgent issues to critical severity', function () {
    alerting_rule($this->organization->id, ['*'], [$this->slack], ['min_severity' => 'critical']);

    event(alerting_issue_opened($this->organization->id, priority: 'urgent'));

    expect(Alert::query()->sole()->severity)->toBe(Severity::Critical)->and(slack_posts())->toBe(1);
});

it('records no_route when no rule matches type or severity', function () {
    alerting_rule($this->organization->id, ['fleet.*'], [$this->slack]);
    alerting_rule($this->organization->id, ['insights.*'], [$this->slack], ['min_severity' => 'critical']);
    alerting_rule($this->organization->id, ['*'], [$this->slack], ['enabled' => false]);

    event(alerting_issue_opened($this->organization->id));

    expect(Alert::query()->sole()->outcome)->toBe(AlertOutcome::NoRoute)->and(slack_posts())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('never routes alerts to another organization', function () {
    [, $other] = memberOf();
    alerting_rule($other->id, ['*'], [alerting_channel($other->id)]);

    event(alerting_issue_opened($this->organization->id));

    expect(Alert::query()->sole()->outcome)->toBe(AlertOutcome::NoRoute)->and(slack_posts())->toBe(0);
});

it('delivers once per channel even when several rules match', function () {
    $webhook = alerting_channel($this->organization->id, ChannelType::Webhook);
    alerting_rule($this->organization->id, ['insights.*'], [$this->slack]);
    alerting_rule($this->organization->id, ['*'], [$this->slack, $webhook]);

    event(alerting_issue_opened($this->organization->id));

    expect(Delivery::query()->count())->toBe(2)->and(slack_posts())->toBe(1);
});

it('alerts once per issue: IssueOpened + ThresholdBreached share a dedup key', function () {
    alerting_rule($this->organization->id, ['insights.*'], [$this->slack]);
    $issue = '01JISSUE000000000000000002';

    event(alerting_issue_opened($this->organization->id, $issue));
    event(new ThresholdBreached($this->organization->id, '01JSITE0000000000000000001', '01JTHRESH00000000000000001', $issue, 'request', 'GET /checkout', 'p95', 1840.5, 1000, 5, "/insights/issues/{$issue}"));
    event(alerting_issue_opened($this->organization->id, $issue));

    expect(slack_posts())->toBe(1)
        ->and(Alert::query()->where('outcome', 'deduplicated')->count())->toBe(2)
        ->and(Notification::query()->count())->toBe(1)
        ->and(DedupState::query()->sole()->occurrences)->toBe(3);
});

it('alerts again on regression after the issue was resolved', function () {
    alerting_rule($this->organization->id, ['insights.issue_opened', 'insights.issue_regressed'], [$this->slack]);
    $issue = '01JISSUE000000000000000003';
    $args = [$issue, $this->organization->id, null, null, 'exception', 'RuntimeException: boom', null, 'none', "/insights/issues/{$issue}"];

    event(alerting_issue_opened($this->organization->id, $issue));
    event(new IssueRegressed(...$args)); // still open episode → deduplicated
    expect(slack_posts())->toBe(1);

    event(new IssueResolved(...$args));
    // The resolution goes to the channel that received the original alert.
    expect(slack_posts())->toBe(2)->and(Alert::query()->where('recovery', true)->sole()->outcome)->toBe(AlertOutcome::Delivered);

    event(new IssueRegressed(...$args));
    expect(slack_posts())->toBe(3)
        ->and(Alert::query()->where('type', 'insights.issue_regressed')->latest('id')->first()->outcome)->toBe(AlertOutcome::Delivered);
});

it('only sends a recovery when the offline alert was delivered', function () {
    $server = '01JSERVER00000000000000001';
    $offline = new AgentWentOffline('01JAGENT000000000000000001', $this->organization->id, $server, new DateTimeImmutable('-5 minutes'));
    $online = new AgentCameOnline('01JAGENT000000000000000001', $this->organization->id, $server, new DateTimeImmutable);

    // Came online without a prior (delivered) offline alert → skipped.
    event($online);
    expect(Alert::query()->sole()->outcome)->toBe(AlertOutcome::RecoverySkipped)->and(slack_posts())->toBe(0);

    alerting_rule($this->organization->id, ['fleet.agent_offline'], [$this->slack]);
    event($offline);
    event($offline);
    event($online);

    $posts = Http::recorded(fn (Request $request) => str_contains($request->url(), 'hooks.slack.com'))->map(fn ($pair) => $pair[0]['text'])->values()->all();
    expect($posts)->toBe(["[CRITICAL] Server agent {$offline->agentId} is offline", "[RESOLVED] Server agent {$offline->agentId} is back online"]);

    // The key is cleared, so a second recovery is skipped again.
    event($online);
    expect(slack_posts())->toBe(2);
});

it('routes generic Alertable events from any module', function () {
    alerting_rule($this->organization->id, ['deployments.*'], [$this->slack]);

    event(new DeploymentFailedForTest($this->organization->id, '01JDEPLOY00000000000000001'));

    $alert = Alert::query()->sole();
    expect($alert->type)->toBe('deployments.failed')->and($alert->severity)->toBe(Severity::Critical)
        ->and($alert->url)->toBe(url('/deployments/01JDEPLOY00000000000000001'))->and(slack_posts())->toBe(1);
});

it('maps heartbeat, provisioning and threshold events', function () {
    alerting_rule($this->organization->id, ['*'], [$this->slack]);

    event(new HeartbeatMissed($this->organization->id, null, '01JSERVER00000000000000001', '01JMON00000000000000000001', '01JISSUE000000000000000009', 'backup', '0 3 * * *', new DateTimeImmutable('2026-09-27T03:02:00Z'), null, '/insights/issues/01JISSUE000000000000000009'));
    event(new ServerProvisioned('01JSERVER00000000000000001', $this->organization->id, 'app', 'web-1'));

    expect(Alert::query()->orderBy('id')->pluck('type')->all())->toBe(['insights.heartbeat_missed', 'servers.provisioned'])
        ->and(Alert::query()->where('type', 'insights.heartbeat_missed')->sole()->severity)->toBe(Severity::Critical)
        ->and(slack_posts())->toBe(2);
});

it('suppresses channel delivery during quiet hours but still notifies in-app', function () {
    Carbon::setTestNow('2026-09-28 21:00:00'); // 23:00 in Berlin
    alerting_rule($this->organization->id, ['*'], [$this->slack], ['quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Berlin', 'allow_critical' => true]]);

    event(alerting_issue_opened($this->organization->id));
    expect(Alert::query()->sole()->outcome)->toBe(AlertOutcome::QuietHours)
        ->and(slack_posts())->toBe(0)
        ->and(Notification::query()->where('user_id', $this->owner->id)->count())->toBe(1);

    // Critical alerts bypass quiet hours.
    event(new AgentWentOffline('01JAGENT000000000000000001', $this->organization->id, null, null));
    expect(slack_posts())->toBe(1);

    Carbon::setTestNow('2026-09-29 06:30:00'); // 08:30 in Berlin
    event(alerting_issue_opened($this->organization->id, '01JISSUE000000000000000077'));
    expect(slack_posts())->toBe(2);
    Carbon::setTestNow();
});

it('rate limits a rule per hour', function () {
    alerting_rule($this->organization->id, ['*'], [$this->slack], ['rate_limit_per_hour' => 2]);

    foreach (range(1, 3) as $i) {
        event(alerting_issue_opened($this->organization->id, sprintf('01JISSUE0000000000000000%02d', $i + 10)));
    }

    expect(slack_posts())->toBe(2)->and(Alert::query()->where('outcome', 'rate_limited')->count())->toBe(1);

    $this->travel(61)->minutes();
    event(alerting_issue_opened($this->organization->id, '01JISSUE000000000000000099'));
    expect(slack_posts())->toBe(3);
});

it('marks failed deliveries with a secret-free error', function () {
    $this->slackResponse = ['no_service', 404];
    alerting_rule($this->organization->id, ['*'], [$this->slack]);

    event(alerting_issue_opened($this->organization->id));

    $delivery = Delivery::query()->sole();
    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->error)->toBe('HTTP 404: no_service')
        ->and($this->slack->refresh()->last_error)->toBe('HTTP 404: no_service');
});

it('skips disabled channels', function () {
    $disabled = alerting_channel($this->organization->id, attributes: ['enabled' => false]);
    alerting_rule($this->organization->id, ['*'], [$disabled]);

    event(alerting_issue_opened($this->organization->id));

    expect(Delivery::query()->count())->toBe(0)->and(Alert::query()->sole()->outcome)->toBe(AlertOutcome::Delivered);
});

it('creates in-app notifications for members allowed to view alerts and broadcasts them', function () {
    Event::fake([NotificationCreated::class]);
    [$viewer] = memberOf($this->organization, Role::Viewer);
    [$blocked] = memberOf($this->organization, Role::Developer);
    [$outsider] = memberOf();

    $real = app(OrganizationAccess::class);
    app()->instance(OrganizationAccess::class, new class($real, $blocked->id) implements OrganizationAccess
    {
        public function __construct(private OrganizationAccess $inner, private string $deny) {}

        public function can(?Authenticatable $user, string $organizationId, string $permission): bool
        {
            return $user?->getAuthIdentifier() !== $this->deny && $this->inner->can($user, $organizationId, $permission);
        }

        public function authorize(?Authenticatable $user, string $organizationId, string $permission): void
        {
            $this->inner->authorize($user, $organizationId, $permission);
        }

        public function isMember(string $userId, string $organizationId): bool
        {
            return $this->inner->isMember($userId, $organizationId);
        }

        public function roleOf(string $userId, string $organizationId): ?Role
        {
            return $this->inner->roleOf($userId, $organizationId);
        }

        public function memberIds(string $organizationId): array
        {
            return $this->inner->memberIds($organizationId);
        }
    });

    alerting_rule($this->organization->id, ['*']); // in-app only rule (no channels)
    event(alerting_issue_opened($this->organization->id));

    expect(Notification::query()->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->owner->id, $viewer->id])->sort()->values()->all());
    Event::assertDispatched(NotificationCreated::class, 2);
    Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $e) => $e->broadcastOn()->name === "private-alerting.users.{$viewer->id}"
        && $e->broadcastAs() === 'notification.created'
        && $e->notification['title'] === 'New issue: RuntimeException: boom');
    Event::assertNotDispatched(NotificationCreated::class, fn (NotificationCreated $e) => in_array($e->userId, [$blocked->id, $outsider->id], true));
});
