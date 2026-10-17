package docker

import (
	"strings"
	"testing"
)

const envNet = "falak-env-01hzy0000000000000000000ab"

// After `up`, every container of the project joins the environment network (created when missing), where the
// environment's databases answer by name.
func TestComposeUpJoinsEnvironmentNetworks(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	e.containers["x1"] = &fcont{id: "x1", name: "shop-web-1", running: true, body: CreateBody{Labels: map[string]string{LabelComposeProject: "shop"}}}
	e.containers["x3"] = &fcont{id: "x3", name: "shop-sandbox-1", running: true, body: CreateBody{Labels: map[string]string{LabelComposeProject: "shop", LabelEnvironmentNetwork: "none"}}}
	e.containers["x2"] = &fcont{id: "x2", name: "other-web-1", running: true, body: CreateBody{Labels: map[string]string{LabelComposeProject: "other"}}}
	fin, col := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/falak/compose/shop",
		Files: []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}}, JoinNetworks: []string{envNet}})
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	if e.networkLabels[envNet]["falak.network"] != "environment" || e.networkLabels[envNet][LabelManaged] != "true" {
		t.Fatalf("labels %v", e.networkLabels[envNet])
	}
	if got := e.networks[envNet]; len(got) != 1 || !strings.HasPrefix(got[0], "x1:") {
		t.Fatalf("joins %v", got)
	}

	fin, _ = exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/falak/compose/shop", JoinNetworks: []string{"bridge"}})
	if fin.ExitCode == nil || *fin.ExitCode != 2 {
		t.Fatalf("a foreign network was accepted: %+v", fin)
	}
}

// A docker site joins its environment's network, created on the spot rather than waited for.
func TestContainerSwapCreatesTheEnvironmentNetwork(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	p.Networks = []NetworkJoin{{Name: envNet, Environment: true}}
	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	if e.networkLabels[envNet]["falak.network"] != "environment" || len(e.networks[envNet]) != 1 {
		t.Fatalf("network %v joins %v", e.networkLabels[envNet], e.networks[envNet])
	}
}
