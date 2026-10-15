package agent

import (
	"bytes"
	"encoding/json"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/OthmanHaba/falak/agent/internal/ebpfobs"
)

// The eBPF probe's status (facts.ebpf and the net.ebpf.configure result) validates against facts.schema.json.
func TestEBPFStatusValidates(t *testing.T) {
	c := compiler(t)
	sch, err := c.Compile(idBase + "facts.schema.json#/$defs/ebpf")
	if err != nil {
		t.Fatal(err)
	}
	pct := 0.12
	for _, s := range []ebpfobs.Status{
		{State: ebpfobs.StateOff, Programs: []string{}},
		{Reason: "kernel 5.4.0 is older than 5.8", Enabled: true, State: ebpfobs.StateUnsupported, Detail: "kernel 5.4.0 is older than 5.8", Programs: []string{}},
		{Supported: true, Enabled: true, State: ebpfobs.StateDegraded, Detail: "run-queue programs shed", Programs: []string{"dns", "tcp"}, OverheadPercent: &pct},
	} {
		b, _ := json.Marshal(s)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s: %v", b, err)
		}
	}
}
