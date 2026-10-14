package nettool

import (
	"crypto/x509"
	"strings"
	"testing"
)

func TestPrintableQuotesControlCharacters(t *testing.T) {
	if printable("OK") != "OK" || printable("Über café") != "Über café" {
		t.Fatal("printable text is kept as is")
	}
	if got := printable("OK\x1b]0;pwned\x07"); got != `"OK\x1b]0;pwned\a"` {
		t.Fatalf("got %s", got)
	}
	c := &x509.Certificate{DNSNames: []string{"a.example", "evil\x1b[2J"}}
	c.Subject.CommonName = "cn\x1b[31m"
	c.Issuer.CommonName = "CA"
	if line := certLine(0, c); strings.ContainsRune(line, 0x1b) || !strings.Contains(line, `"cn\x1b[31m"`) || !strings.Contains(line, `"evil\x1b[2J"`) {
		t.Fatalf("cert line %q", line)
	}
}
