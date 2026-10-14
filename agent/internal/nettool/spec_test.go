package nettool

import (
	"strings"
	"testing"
)

func TestValidHost(t *testing.T) {
	good := []string{"example.com", "example.com.", "a", "db", "localhost", "my_service", "x-1.y-2.example", "127.0.0.1", "::1", "2001:db8::1",
		strings.Repeat("a", 63) + ".com"}
	bad := []string{"", "-oProxyCommand=x", "-c", "a;b", "a b", "a|b", "$(id)", "`id`", "a/b", "a..b", ".a", "a-.com", "-a.com",
		strings.Repeat("a", 64) + ".com", strings.Repeat("a.", 130) + "com", "fe80::1%eth0", "exa\nmple.com", "ex\x00ample", "*.example.com"}
	for _, h := range good {
		if !ValidHost(h) {
			t.Errorf("ValidHost(%q) = false, want true", h)
		}
	}
	for _, h := range bad {
		if ValidHost(h) {
			t.Errorf("ValidHost(%q) = true, want false", h)
		}
	}
}

func TestValidateDefaultsAndRanges(t *testing.T) {
	s := Spec{Tool: ToolPing, Target: "example.com"}
	if err := s.Validate(); err != nil || s.Count != 4 || s.TimeoutS != DefaultTimeoutS {
		t.Fatalf("ping defaults: %v %+v", err, s)
	}
	s = Spec{Tool: ToolTraceroute, Target: "1.1.1.1"}
	if err := s.Validate(); err != nil || s.MaxHops != 20 {
		t.Fatalf("traceroute defaults: %v %+v", err, s)
	}
	s = Spec{Tool: ToolDNS, Target: "example.com", RecordType: "mx"}
	if err := s.Validate(); err != nil || s.RecordType != "MX" {
		t.Fatalf("dns: %v %+v", err, s)
	}
	s = Spec{Tool: ToolHTTP, URL: "https://example.com/health?x=1"}
	if err := s.Validate(); err != nil || s.Method != "GET" {
		t.Fatalf("http: %v %+v", err, s)
	}
	s = Spec{Tool: ToolTCP, Target: "db", Port: 5432}
	if err := s.Validate(); err != nil || s.Count != 3 {
		t.Fatalf("tcp: %v %+v", err, s)
	}
}

func TestValidateRefuses(t *testing.T) {
	cases := map[string]Spec{
		"unknown tool":            {Tool: "nmap", Target: "x"},
		"no target":               {Tool: ToolPing},
		"option injection":        {Tool: ToolPing, Target: "-f"},
		"shell metachar":          {Tool: ToolTraceroute, Target: "a;reboot"},
		"count too high":          {Tool: ToolPing, Target: "a", Count: 21},
		"tcp count too high":      {Tool: ToolTCP, Target: "a", Port: 80, Count: 11},
		"hops too high":           {Tool: ToolTraceroute, Target: "a", MaxHops: 31},
		"timeout too high":        {Tool: ToolPing, Target: "a", TimeoutS: 61},
		"negative timeout":        {Tool: ToolPing, Target: "a", TimeoutS: -1},
		"port missing":            {Tool: ToolTCP, Target: "a"},
		"port too high":           {Tool: ToolTCP, Target: "a", Port: 70000},
		"bad record type":         {Tool: ToolDNS, Target: "a", RecordType: "ANY"},
		"nameserver not ip":       {Tool: ToolDNS, Target: "a", Nameserver: "dns.google"},
		"ptr needs ip":            {Tool: ToolDNS, Target: "example.com", RecordType: "PTR"},
		"http method":             {Tool: ToolHTTP, URL: "https://a", Method: "POST"},
		"http scheme":             {Tool: ToolHTTP, URL: "file:///etc/passwd"},
		"http gopher":             {Tool: ToolHTTP, URL: "gopher://a/"},
		"http creds":              {Tool: ToolHTTP, URL: "https://u:p@a/"},
		"http space":              {Tool: ToolHTTP, URL: "https://a/ b"},
		"http newline":            {Tool: ToolHTTP, URL: "https://a/\r\nX: y"},
		"http bad host":           {Tool: ToolHTTP, URL: "https://-a/"},
		"http bad port":           {Tool: ToolHTTP, URL: "https://a:99999/"},
		"http port zero":          {Tool: ToolHTTP, URL: "https://a:0/"},
		"http too long":           {Tool: ToolHTTP, URL: "https://a/" + strings.Repeat("x", 2048)},
		"connect_to not ip":       {Tool: ToolHTTP, URL: "https://a/", ConnectTo: "b"},
		"field of another tool":   {Tool: ToolPing, Target: "a", Port: 80},
		"url on tcp":              {Tool: ToolTCP, Target: "a", Port: 80, URL: "https://a"},
		"insecure on dns":         {Tool: ToolDNS, Target: "a", Insecure: true},
		"target on http":          {Tool: ToolHTTP, URL: "https://a/", Target: "a"},
		"container name and lbls": {Tool: ToolPing, Target: "a", Container: &Container{Name: "x1", Labels: map[string]string{"a": "b"}}},
		"container empty":         {Tool: ToolPing, Target: "a", Container: &Container{}},
		"container bad name":      {Tool: ToolPing, Target: "a", Container: &Container{Name: "-x"}},
		"container bad label":     {Tool: ToolPing, Target: "a", Container: &Container{Labels: map[string]string{"a": "b c"}}},
	}
	for name, s := range cases {
		if err := s.Validate(); err == nil {
			t.Errorf("%s: accepted %+v", name, s)
		}
	}
}

func TestValidateAcceptsContainer(t *testing.T) {
	s := Spec{Tool: ToolTCP, Target: "db", Port: 5432, Container: &Container{Labels: map[string]string{"com.docker.compose.project": "shop", "com.docker.compose.service": "web"}}}
	if err := s.Validate(); err != nil {
		t.Fatal(err)
	}
	s = Spec{Tool: ToolDNS, Target: "1.1.1.1", RecordType: "PTR", Container: &Container{Name: "falak-db-01abc"}}
	if err := s.Validate(); err != nil {
		t.Fatal(err)
	}
}
