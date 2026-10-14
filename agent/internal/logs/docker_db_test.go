package logs

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// A database container's log is the engine's (kind db.engine), queryable per instance (docs/plans/OBSERVABILITY.md
// phase 3).
func TestDockerFollowerDatabaseInstance(t *testing.T) {
	sock := filepath.Join(os.TempDir(), fmt.Sprintf("falak-dockdb-%d.sock", time.Now().UnixNano()))
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer os.Remove(sock)
	mux := http.NewServeMux()
	mux.HandleFunc("/containers/json", func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode([]map[string]any{{"Id": "db0c0n7a1ner", "Names": []string{"/falak-db-01hzyinst00000000000000001"},
			"Labels": map[string]string{"falak.db.instance": "01hzyinst00000000000000001", "falak.db.engine": "postgres"}}})
	})
	mux.HandleFunc("/containers/db0c0n7a1ner/json", func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{"Config":{"Tty":false}}`)) })
	mux.HandleFunc("/containers/db0c0n7a1ner/logs", func(w http.ResponseWriter, r *http.Request) {
		// A slow statement (with its literal) never reaches the sink: the observer ships it normalized.
		w.Write(frame(2, "2026-10-14T09:59:59Z 2026-10-14 09:59:59.000 UTC [7] app@shop LOG:  duration: 1500.0 ms  statement: SELECT 'secret'\n"))
		w.Write(frame(2, "2026-10-14T10:00:00Z 2026-10-14 10:00:00.000 UTC [1] LOG:  database system is ready to accept connections\n"))
		w.(http.Flusher).Flush()
		<-r.Context().Done()
	})
	srv := &http.Server{Handler: mux}
	go srv.Serve(l)
	defer srv.Close()
	sink := &memSink{}
	d := NewDocker(sock, sink, nil)
	d.Configure(true, nil)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	d.Sync(ctx)
	deadline := time.Now().Add(3 * time.Second)
	for len(sink.bodies()) < 1 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	sink.mu.Lock()
	defer sink.mu.Unlock()
	if len(sink.recs) != 1 {
		t.Fatalf("recs %+v", sink.recs)
	}
	r := sink.recs[0]
	if r.Service != "falak-db-01hzyinst00000000000000001" || r.Kind != "db.engine" || r.Attrs["falak.db.instance.id"] != "01hzyinst00000000000000001" || r.Site != "" {
		t.Fatalf("record %+v", r)
	}
}
