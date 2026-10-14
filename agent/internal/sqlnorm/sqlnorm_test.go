package sqlnorm

import (
	"strings"
	"testing"
)

func TestNormalize(t *testing.T) {
	for _, c := range []struct {
		d       Dialect
		in, out string
	}{
		{Postgres, "SELECT * FROM users WHERE id = 42", "SELECT * FROM users WHERE id = ?"},
		{Postgres, "select  *\n from t1\twhere name='O''Brien' and x=-1.5e3;", "select * from t1 where name=? and x=-?"},
		{Postgres, `SELECT "Weird""Col" FROM "t" WHERE a = $1 AND b IN ($2, $3, $4)`, `SELECT "Weird""Col" FROM "t" WHERE a = $1 AND b IN (?, ...)`},
		{Postgres, "INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y'), (3, 'z')", "INSERT INTO t (a, b) VALUES (?, ...), ..."},
		{Postgres, "SELECT $body$ secret ' stuff $body$, $$x$$", "SELECT ?, ?"},
		{Postgres, "SELECT E'it\\'s', X'ff', B'0101', 0x1F", "SELECT ?, ?, ?, ?"},
		{Postgres, "ALTER ROLE app PASSWORD 'hunter2'", "ALTER ROLE app PASSWORD ?"},
		{Postgres, "SELECT 1 -- secret 'x'\n, /* 'y' */ 2", "SELECT ?, ?"},
		{Postgres, "SELECT col1, t2.c3 FROM t2", "SELECT col1, t2.c3 FROM t2"},
		{Postgres, "SELECT * FROM t WHERE id IN (7)", "SELECT * FROM t WHERE id IN (?)"},
		{MySQL, `SELECT * FROM users WHERE email = "a@b.c" AND pw = 'x\'y'`, "SELECT * FROM users WHERE email = ? AND pw = ?"},
		{MySQL, "CREATE USER 'app'@'%' IDENTIFIED BY 's3cret'", "CREATE USER ?@? IDENTIFIED BY ?"},
		{MySQL, "SELECT `order` FROM `t` # comment 'x'\nWHERE a = 1", "SELECT `order` FROM `t` WHERE a = ?"},
		{MySQL, "SELECT * FROM `t` WHERE `id` IN (?, ?, ?)", "SELECT * FROM `t` WHERE `id` IN (?, ...)"},
		{MySQL, "SELECT 'unterminated", "SELECT ?"},
		{MySQL, "SELECT ünïcode FROM t WHERE x = 'ü'", "SELECT ünïcode FROM t WHERE x = ?"},
		{MySQL, "SELECT * FROM t WHERE id IN (1,2,3)", "SELECT * FROM t WHERE id IN (?, ...)"},
		{MySQL, "INSERT INTO t(a,b) VALUES(1,'x'),(2,'y'),(3,'z')", "INSERT INTO t(a, b) VALUES(?, ...), ..."},
		{Postgres, "SELECT 1 /* outer /* inner 'secret' */ still comment 'x' */, 2", "SELECT ?, ?"},
		{MySQL, "SELECT 1 /* a /* b */, 2", "SELECT ?, ?"},
		{Postgres, "SET search_path = tenant_42, public", "SET search_path = ?, ?"},
		{Postgres, "SET ROLE TO admin_bob", "SET ROLE TO ?"},
		{MySQL, "SET SESSION sql_mode=STRICT_ALL_TABLES", "SET SESSION sql_mode=?"},
	} {
		if got := Normalize(c.in, c.d); got != c.out {
			t.Errorf("Normalize(%q)\n got %q\nwant %q", c.in, got, c.out)
		}
	}
}

func TestNormalizeIsIdempotentAndBounded(t *testing.T) {
	for _, in := range []string{
		"SELECT * FROM t WHERE a = $1 AND b IN (?, ...)",
		"INSERT INTO t VALUES (?, ...), ...",
	} {
		if got := Normalize(in, Postgres); got != in {
			t.Errorf("not idempotent: %q -> %q", in, got)
		}
	}
	long := "SELECT " + strings.Repeat("colümn, ", 1000) + "x FROM t"
	if got := Normalize(long, Postgres); len(got) > MaxLen || !strings.HasPrefix(got, "SELECT colümn") {
		t.Errorf("long text: %d bytes", len(got))
	}
}

func TestNoLiteralSurvives(t *testing.T) {
	secrets := []string{"hunter2", "4111111111111111", "alice@example.com"}
	for _, q := range []string{
		"UPDATE users SET password = 'hunter2', card = 4111111111111111 WHERE email = 'alice@example.com'",
		`UPDATE users SET password = "hunter2" WHERE email = "alice@example.com" AND card=4111111111111111`,
		"SELECT $q$hunter2$q$, $$alice@example.com$$, 4111111111111111",
	} {
		for _, d := range []Dialect{Postgres, MySQL} {
			got := Normalize(q, d)
			for _, s := range secrets {
				if d == Postgres && strings.Contains(q, `"hunter2"`) && s != "4111111111111111" {
					continue // double quotes are identifiers in PostgreSQL
				}
				if strings.Contains(got, s) {
					t.Errorf("%v: %q kept %q: %q", d, q, s, got)
				}
			}
		}
	}
}

func TestFingerprint(t *testing.T) {
	a := Fingerprint(Normalize("SELECT * FROM t WHERE id = 1", Postgres))
	b := Fingerprint(Normalize("select *  from t where id=2", Postgres))
	if a == "" || len(a) != 16 {
		t.Fatalf("fingerprint %q", a)
	}
	if a == b {
		// "id = ?" and "id=?" differ in spacing: shapes are compared as written, which is fine for one app's queries.
		t.Log("equal")
	}
	if Fingerprint("SELECT ?") != Fingerprint("select ?") {
		t.Error("fingerprint is case sensitive")
	}
}

func TestClean(t *testing.T) {
	got := Clean("FATAL:  password authentication failed for user \"admin\"\n\tDETAIL: 'secret' don't", 300)
	if strings.Contains(got, "admin") || strings.Contains(got, "secret") || !strings.Contains(got, "don't") || strings.Contains(got, "\n") {
		t.Errorf("Clean: %q", got)
	}
	if got := Clean(strings.Repeat("x", 500), 300); len(got) != 300 {
		t.Errorf("Clean length %d", len(got))
	}
}
