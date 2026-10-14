package otlp

import (
	"encoding/binary"
	"fmt"
	"math"

	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// Str builds a string KeyValue.
func Str(k, v string) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: v}}}
}

// Any builds a KeyValue from a Go value (string, bool, ints, floats; others via fmt).
func Any(k string, v any) *commonpb.KeyValue {
	return &commonpb.KeyValue{Key: k, Value: AnyValue(v)}
}

// AnyValue converts a Go value to an OTLP AnyValue.
func AnyValue(v any) *commonpb.AnyValue {
	switch t := v.(type) {
	case string:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: t}}
	case bool:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_BoolValue{BoolValue: t}}
	case int:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: int64(t)}}
	case int32:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: int64(t)}}
	case int64:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: t}}
	case uint64:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: int64(t)}}
	case float64:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_DoubleValue{DoubleValue: t}}
	case float32:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_DoubleValue{DoubleValue: float64(t)}}
	case nil:
		return &commonpb.AnyValue{}
	default:
		return &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: fmt.Sprint(t)}}
	}
}

// AttrString renders an attribute value as a string ("" when absent).
func AttrString(v *commonpb.AnyValue) string {
	if v == nil {
		return ""
	}
	switch t := v.Value.(type) {
	case *commonpb.AnyValue_StringValue:
		return t.StringValue
	case *commonpb.AnyValue_IntValue:
		return fmt.Sprint(t.IntValue)
	case *commonpb.AnyValue_DoubleValue:
		return fmt.Sprint(t.DoubleValue)
	case *commonpb.AnyValue_BoolValue:
		return fmt.Sprint(t.BoolValue)
	}
	return ""
}

// Lookup returns the string value of key in kvs.
func Lookup(kvs []*commonpb.KeyValue, key string) (string, bool) {
	for _, kv := range kvs {
		if kv.GetKey() == key {
			return AttrString(kv.GetValue()), true
		}
	}
	return "", false
}

// LookupValue returns the raw value of key in kvs.
func LookupValue(kvs []*commonpb.KeyValue, key string) *commonpb.AnyValue {
	for _, kv := range kvs {
		if kv.GetKey() == key {
			return kv.GetValue()
		}
	}
	return nil
}

// Site describes a site hosted on this server, used for resource enrichment.
type Site struct {
	Slug         string `json:"slug"`
	SiteID       string `json:"site_id"`
	Environment  string `json:"environment,omitempty"`
	DeploymentID string `json:"deployment_id,omitempty"`
	ReleaseID    string `json:"release_id,omitempty"`
	// UnixUser, Isolated and Runtime let the workload metrics measure a native site without a slice by its unix
	// user's processes: all of them for an isolated site (a user of its own), those attributed to the site for a
	// non-isolated one (its user is the one every such site, and the servers' admin logins, share). Runtime is the
	// control plane's SiteRuntime value (php-fpm, frankenphp, node, … docker, compose, function); container runtimes
	// are measured through their containers.
	UnixUser string `json:"unix_user,omitempty"`
	Isolated bool   `json:"isolated,omitempty"`
	Runtime  string `json:"runtime,omitempty"`
}

// Config is the hot-swappable relay configuration.
type Config struct {
	Endpoint    string            // OTLP/HTTP base URL (…:4318); "" = buffer to disk only
	Headers     map[string]string // extra export headers (auth)
	OrgID       string
	ServerID    string
	HostName    string
	Environment string // default deployment.environment.name
	Sites       []Site
	TracesRatio float64 // 0..1; 1 keeps everything
	BufferMax   int64   // disk buffer bound in bytes (default 64 MiB)
}

func (c *Config) siteByID(id string) *Site {
	for i := range c.Sites {
		if c.Sites[i].SiteID == id {
			return &c.Sites[i]
		}
	}
	return nil
}

func (c *Config) siteBySlug(slug string) *Site {
	for i := range c.Sites {
		if c.Sites[i].Slug == slug {
			return &c.Sites[i]
		}
	}
	return nil
}

// AgentService is the service.name of records produced by the agent itself.
const AgentService = "falak-agent"

// WorkloadsScope is the instrumentation scope of the agent's falak.workload.* metrics. They describe the site's
// processes, not a release of it: they get no falak.deployment.id / falak.release.id, so a deployment does not split
// every workload series in two (and double sum(rate()) while both are in the rate window).
const WorkloadsScope = "falak-agent/workloads"

// Enrich fills missing resource attributes per the telemetry contract. Attributes set by the app are
// never overwritten. The site is resolved from falak.site.id, then service.name (site slug).
func Enrich(res *resourcepb.Resource, cfg *Config) *resourcepb.Resource {
	return enrich(res, cfg, true)
}

// EnrichMetrics is Enrich for one ResourceMetrics: the agent's workload metrics get no deployment / release ids.
func EnrichMetrics(rm *metricspb.ResourceMetrics, cfg *Config) {
	release := true
	for _, sm := range rm.GetScopeMetrics() {
		if sm.GetScope().GetName() == WorkloadsScope {
			release = false
		}
	}
	rm.Resource = enrich(rm.Resource, cfg, release)
}

func enrich(res *resourcepb.Resource, cfg *Config, release bool) *resourcepb.Resource {
	if res == nil {
		res = &resourcepb.Resource{}
	}
	var site *Site
	if id, ok := Lookup(res.Attributes, "falak.site.id"); ok && id != "" {
		site = cfg.siteByID(id)
	}
	if site == nil {
		if svc, ok := Lookup(res.Attributes, "service.name"); ok && svc != "" {
			site = cfg.siteBySlug(svc)
		}
	}
	add := func(k, v string) {
		if v == "" {
			return
		}
		if _, ok := Lookup(res.Attributes, k); ok {
			return
		}
		res.Attributes = append(res.Attributes, Str(k, v))
	}
	env := cfg.Environment
	svc, _ := Lookup(res.Attributes, "service.name")
	if site != nil {
		add("service.name", site.Slug)
		add("falak.site.id", site.SiteID)
		// The agent's own records (e.g. deployment lifecycle events) carry their deployment/release
		// ids as record attributes; the site's *currently active* ids would contradict them. Workload metrics
		// (release false) get none either.
		if svc != AgentService && release {
			add("falak.deployment.id", site.DeploymentID)
			add("falak.release.id", site.ReleaseID)
		}
		if site.Environment != "" {
			env = site.Environment
		}
	}
	add("deployment.environment.name", env)
	add("falak.org.id", cfg.OrgID)
	add("falak.server.id", cfg.ServerID)
	add("host.name", cfg.HostName)
	return res
}

// keepSpan implements trace-id ratio sampling. Spans that are errors or carry an exception event are
// always kept so Insights and Tempo never miss failures.
func keepSpan(sp *tracepb.Span, ratio float64) bool {
	if ratio >= 1 {
		return true
	}
	if sp.GetStatus().GetCode() == tracepb.Status_STATUS_CODE_ERROR {
		return true
	}
	for _, ev := range sp.GetEvents() {
		if ev.GetName() == "exception" {
			return true
		}
	}
	if ratio <= 0 {
		return false
	}
	return traceIDKeep(sp.GetTraceId(), ratio)
}

// traceIDKeep is deterministic per trace (all spans of a trace get the same decision): the low 8
// bytes of a W3C trace id are random.
func traceIDKeep(tid []byte, ratio float64) bool {
	if len(tid) < 8 {
		return true
	}
	v := binary.BigEndian.Uint64(tid[len(tid)-8:])
	return float64(v) < ratio*math.MaxUint64
}

// sample drops unsampled spans in place and removes empty scopes/resources.
func sample(rs []*tracepb.ResourceSpans, ratio float64) []*tracepb.ResourceSpans {
	if ratio >= 1 {
		return rs
	}
	out := rs[:0]
	for _, r := range rs {
		scopes := r.ScopeSpans[:0]
		for _, ss := range r.ScopeSpans {
			spans := ss.Spans[:0]
			for _, sp := range ss.Spans {
				if keepSpan(sp, ratio) {
					spans = append(spans, sp)
				}
			}
			ss.Spans = spans
			if len(spans) > 0 {
				scopes = append(scopes, ss)
			}
		}
		r.ScopeSpans = scopes
		if len(scopes) > 0 {
			out = append(out, r)
		}
	}
	return out
}
