package configspec

import "testing"

func TestLocate(t *testing.T) {
	src := []byte(`version: 1
units:
  web:
    type: app
    runtime: node
    deploy:
      strategy: sideways
    bogus: 1
    domains: [a.test, b.test]
    env: &e
      A: x
  api:
    <<: {type: app}
    env: *e
`)
	p := NewPositions(src)
	cases := []struct {
		ptr, prop string
		line, col int
	}{
		{"/units/web/deploy/strategy", "", 7, 17},
		{"/units/web", "bogus", 8, 5},
		{"/units/web/deploy", "", 6, 5},
		{"/units/web/domains/1", "", 9, 23},
		{"/units/web/missing/deep", "", 3, 3},
		{"/units/api/type", "", 13, 16},
		{"/units/api/env/A", "", 11, 10},
		{"", "", 1, 1},
	}
	for _, c := range cases {
		l, col := p.Locate(c.ptr, c.prop)
		if l != c.line || col != c.col {
			t.Errorf("%s %s: got %d:%d, want %d:%d", c.ptr, c.prop, l, col, c.line, c.col)
		}
	}
	if l, c := NewPositions([]byte("a: [")).Locate("/a", ""); l != 0 || c != 0 {
		t.Errorf("broken file: %d:%d", l, c)
	}
	if n := MessageLine("yaml: line 3: mapping values are not allowed"); n != 3 {
		t.Errorf("MessageLine: %d", n)
	}
}

func TestValidateNamesTheUnknownProperty(t *testing.T) {
	doc, err := Parse([]byte("version: 1\nunits:\n  web:\n    type: app\n    runtime: node\n    bogus: 1\n"), "yaml")
	if err != nil {
		t.Fatal(err)
	}
	issues, err := Validate(doc)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, i := range issues {
		if i.Pointer == "/units/web" && i.Property == "bogus" {
			found = true
		}
	}
	if !found {
		t.Fatalf("%+v", issues)
	}
}
