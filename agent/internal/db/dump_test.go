package db

import (
	"bytes"
	"context"
	"io"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

func TestDumpStreamsTheLogicalDumpUnencrypted(t *testing.T) {
	h := newHarness(t)
	h.run.On("docker exec falak-db-"+instID+" falak-db backup logical --database shop --out -",
		runner.Result{Stdout: []byte("PGDMP-data"), Stderr: []byte(`falak-db-result: {"bytes":10}` + "\n")})
	var out bytes.Buffer
	if err := h.db.Dump(context.Background(), instID, "postgres", "shop", &out); err != nil || out.String() != "PGDMP-data" {
		t.Fatalf("%q %v", out.String(), err)
	}
	// No result line: the dump didn't finish.
	h.run.On("docker exec falak-db-"+instID+" falak-db backup logical --database cut", runner.Result{Stdout: []byte("PGD")})
	if err := h.db.Dump(context.Background(), instID, "postgres", "cut", io.Discard); err == nil {
		t.Fatal("a dump without its result accepted")
	}
	for _, c := range [][3]string{{"x", "postgres", "shop"}, {instID, "redis", "shop"}, {instID, "postgres", "a;b"}, {instID, "oracle", "shop"}} {
		if err := h.db.Dump(context.Background(), c[0], c[1], c[2], io.Discard); err == nil {
			t.Errorf("%v accepted", c)
		}
	}
}
