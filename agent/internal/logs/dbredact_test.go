package logs

import (
	"strings"
	"testing"
)

func TestPGLogFilter(t *testing.T) {
	f := dbLineFilter(map[string]string{"falak.db.instance": "01hzyinst00000000000000001", "falak.db.engine": "postgres"})
	if f == nil {
		t.Fatal("no filter for a postgres instance")
	}
	p := "2026-10-14 12:00:00.000 UTC [42] app@shop "
	in := []string{
		p + "LOG:  duration: 1500.2 ms  statement: SELECT * FROM users WHERE email = 'alice@example.com'",
		"\tAND pin = '1234'",
		p + "LOG:  duration: 1200.0 ms  execute <unnamed>: SELECT * FROM t WHERE a = $1",
		p + "DETAIL:  parameters: $1 = 'secret-param'",
		"\t, $2 = 'more-secret'",
		p + "ERROR:  duplicate key value violates unique constraint \"users_email_key\"",
		p + "DETAIL:  Key (email)=(alice@example.com) already exists.",
		p + "STATEMENT:  INSERT INTO users (email) VALUES ('alice@example.com')",
		"\tRETURNING id, 'x'",
		p + "ERROR:  new row for relation \"t\" violates check constraint \"c\"",
		p + "DETAIL:  Failing row contains (1, secret-row, null).",
		p + "LOG:  statement: UPDATE accounts SET balance = 100",
		"2026-10-14 12:00:01.000 UTC [1] LOG:  checkpoint complete: wrote 3 buffers",
		p + "FATAL:  password authentication failed for user \"app\"",
	}
	var out []string
	for _, l := range in {
		if got, ok := f(l); ok {
			out = append(out, got)
		}
	}
	joined := strings.Join(out, "\n")
	for _, secret := range []string{"alice@example.com", "1234", "secret-param", "more-secret", "secret-row", "RETURNING", "balance = 100", "SELECT"} {
		if strings.Contains(joined, secret) {
			t.Errorf("%q shipped:\n%s", secret, joined)
		}
	}
	for _, kept := range []string{"duplicate key value", "Key (email)=(?) already exists.", "Failing row contains (?).", "checkpoint complete", "password authentication failed"} {
		if !strings.Contains(joined, kept) {
			t.Errorf("%q dropped:\n%s", kept, joined)
		}
	}
	if len(out) != 6 {
		t.Errorf("%d lines kept:\n%s", len(out), joined)
	}

	// Other engines and site containers are shipped as they are.
	if dbLineFilter(map[string]string{"falak.db.instance": "x", "falak.db.engine": "mysql"}) != nil || dbLineFilter(map[string]string{"falak.site": "shop"}) != nil {
		t.Error("a filter for a container that is not a postgres instance")
	}
}
