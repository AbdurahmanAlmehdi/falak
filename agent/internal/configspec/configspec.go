// Package configspec is the falak.yaml v1 contract on the Go side: the JSON Schema (an embedded copy of
// contracts/config/v1.schema.json), the YAML/JSON reading rules the control plane shares, and the environment merge
// with its reference checks (docs/plans/CONFIG_AS_CODE.md, "Phase 0 contract (fixed)"). `falak validate` runs
// offline on it.
package configspec

import (
	"bytes"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"

	"github.com/santhosh-tekuri/jsonschema/v6"
	"golang.org/x/text/language"
	"golang.org/x/text/message"
	"gopkg.in/yaml.v3"
)

// SchemaID is the schema's $id (and the value of a file's `$schema`).
const SchemaID = "https://falak.sh/schema/v1.json"

// SchemaJSON is contracts/config/v1.schema.json, copied here because go:embed can't reach outside the module
// (schema_test.go fails when the copy drifts).
//
//go:embed v1.schema.json
var SchemaJSON []byte

// Issue codes (the `code` of API errors and of the shared cases).
const (
	CodeSchema             = "schema"
	CodeParse              = "parse"
	CodeParseEmpty         = "parse.empty"
	CodeParseDuplicateKey  = "parse.duplicate_key"
	CodeParseTimestamp     = "parse.timestamp"
	CodeParseNumber        = "parse.number"
	CodeParseTag           = "parse.tag"
	CodeParseKey           = "parse.key"
	CodeParseDocuments     = "parse.multiple_documents"
	CodeEnvironmentUnknown = "environment.unknown"
	CodeOverrideType       = "override.type"
	CodeRefMalformed       = "reference.malformed"
	CodeRefSecretName      = "reference.secret_name"
	CodeRefUnknownKey      = "reference.unknown_key"
	CodeRefNotAllowed      = "reference.not_allowed"
	CodeRefExternal        = "reference.external" // a warning: checked against the project's canvas at plan time
)

// Issue is one error or warning about a file. Pointer is an RFC 6901 JSON Pointer into the parsed document (into the
// resolved document for Resolve's issues, with Source the pointer of the value in the file).
type Issue struct {
	Code    string `json:"code"`
	Pointer string `json:"pointer"`
	Source  string `json:"source,omitempty"`
	Message string `json:"message,omitempty"`
}

func (i Issue) Error() string { return fmt.Sprintf("%s at %q: %s", i.Code, i.Pointer, i.Message) }

// maxNodes bounds alias expansion (a "billion laughs" file).
const maxNodes = 200000

// sharedInt is the integer syntax yaml.v3 and symfony/yaml agree on: decimal (with _ separators), 0x hex and 0o
// octal. Other plain scalars yaml.v3 reads as ints (0755, 08, 0b11, -0) are strings for symfony/yaml, and so for the
// contract.
var sharedInt = regexp.MustCompile(`^([-+]?[1-9][0-9_]*|0|0x[0-9a-fA-F]+|0o[0-7]+)$`)

// sharedFloat is the float syntax both read (a dot or an exponent, no leading zeros); others (08) are strings.
var sharedFloat = regexp.MustCompile(`^[-+]?(0|[1-9][0-9_]*)?(\.[0-9]+)?([eE][-+]?[0-9]+)?$`)

// Parse reads a falak.yaml (format "yaml") or falak.json (format "json") into the JSON data model: map[string]any,
// []any, string, int64, float64, bool, nil.
func Parse(data []byte, format string) (any, error) {
	data = bytes.TrimPrefix(data, []byte("\xef\xbb\xbf"))

	if format == "json" {
		dec := json.NewDecoder(bytes.NewReader(data))
		dec.UseNumber()
		var v any
		if err := dec.Decode(&v); err != nil {
			if err == io.EOF {
				return nil, Issue{Code: CodeParseEmpty, Message: "the file is empty"}
			}
			return nil, Issue{Code: CodeParse, Message: err.Error()}
		}
		if dec.More() {
			return nil, Issue{Code: CodeParse, Message: "data after the top-level value"}
		}
		return fromJSON(v), nil
	}

	dec := yaml.NewDecoder(bytes.NewReader(data))
	var doc yaml.Node
	if err := dec.Decode(&doc); err != nil {
		if err == io.EOF {
			return nil, Issue{Code: CodeParseEmpty, Message: "the file is empty"}
		}
		return nil, Issue{Code: CodeParse, Message: err.Error()}
	}
	var extra yaml.Node
	if err := dec.Decode(&extra); err != io.EOF {
		return nil, Issue{Code: CodeParseDocuments, Message: "only one YAML document is allowed"}
	}
	if len(doc.Content) == 0 {
		return nil, Issue{Code: CodeParseEmpty, Message: "the file is empty"}
	}
	w := walker{}
	return w.value(doc.Content[0], "")
}

func fromJSON(v any) any {
	switch t := v.(type) {
	case map[string]any:
		for k, x := range t {
			t[k] = fromJSON(x)
		}
		return t
	case []any:
		for i, x := range t {
			t[i] = fromJSON(x)
		}
		return t
	case json.Number:
		if i, err := t.Int64(); err == nil {
			return i
		}
		f, _ := t.Float64()
		return f
	}
	return v
}

type walker struct{ nodes int }

func (w *walker) value(n *yaml.Node, ptr string) (any, error) {
	if w.nodes++; w.nodes > maxNodes {
		return nil, Issue{Code: CodeParse, Pointer: ptr, Message: "the file expands to too many values (aliases)"}
	}
	switch n.Kind {
	case yaml.AliasNode:
		return w.value(n.Alias, ptr)
	case yaml.SequenceNode:
		out := make([]any, 0, len(n.Content))
		for i, c := range n.Content {
			v, err := w.value(c, ptr+"/"+strconv.Itoa(i))
			if err != nil {
				return nil, err
			}
			out = append(out, v)
		}
		return out, nil
	case yaml.MappingNode:
		return w.mapping(n, ptr)
	case yaml.ScalarNode:
		return scalar(n, ptr)
	}
	return nil, Issue{Code: CodeParse, Pointer: ptr, Message: "unsupported YAML node"}
}

// mapping: explicit keys win over merged (<<) ones; among merged maps, the first one listed wins.
func (w *walker) mapping(n *yaml.Node, ptr string) (map[string]any, error) {
	out := map[string]any{}
	var merges []*yaml.Node
	for i := 0; i+1 < len(n.Content); i += 2 {
		k, v := n.Content[i], n.Content[i+1]
		if k.Kind == yaml.ScalarNode && k.ShortTag() == "!!merge" {
			if v.Kind == yaml.AliasNode {
				v = v.Alias
			}
			if v.Kind == yaml.SequenceNode {
				for _, c := range v.Content {
					merges = append(merges, c)
				}
			} else {
				merges = append(merges, v)
			}
			continue
		}
		if k.Kind != yaml.ScalarNode {
			return nil, Issue{Code: CodeParseKey, Pointer: ptr, Message: fmt.Sprintf("line %d: keys must be plain strings", k.Line)}
		}
		key := k.Value
		child := ptr + "/" + escape(key)
		if _, dup := out[key]; dup {
			return nil, Issue{Code: CodeParseDuplicateKey, Pointer: child, Message: fmt.Sprintf("line %d: %q is defined twice", k.Line, key)}
		}
		val, err := w.value(v, child)
		if err != nil {
			return nil, err
		}
		out[key] = val
	}
	for _, m := range merges {
		if m.Kind == yaml.AliasNode {
			m = m.Alias
		}
		if m.Kind != yaml.MappingNode {
			return nil, Issue{Code: CodeParse, Pointer: ptr, Message: "<< merges only mappings"}
		}
		merged, err := w.mapping(m, ptr)
		if err != nil {
			return nil, err
		}
		for k, v := range merged {
			if _, ok := out[k]; !ok {
				out[k] = v
			}
		}
	}
	return out, nil
}

func scalar(n *yaml.Node, ptr string) (any, error) {
	switch n.ShortTag() {
	case "!!str":
		return n.Value, nil
	case "!!null":
		return nil, nil
	case "!!bool":
		var b bool
		if err := n.Decode(&b); err != nil {
			return nil, Issue{Code: CodeParse, Pointer: ptr, Message: err.Error()}
		}
		return b, nil
	case "!!int":
		if !sharedInt.MatchString(n.Value) {
			return n.Value, nil
		}
		var i int64
		if err := n.Decode(&i); err != nil {
			return nil, Issue{Code: CodeParseNumber, Pointer: ptr, Message: fmt.Sprintf("line %d: %v", n.Line, err)}
		}
		return i, nil
	case "!!float":
		special := strings.TrimLeft(strings.ToLower(n.Value), "+-")
		if special != ".inf" && special != ".nan" && !sharedFloat.MatchString(n.Value) {
			return n.Value, nil
		}
		var f float64
		if err := n.Decode(&f); err != nil || math.IsInf(f, 0) || math.IsNaN(f) {
			return nil, Issue{Code: CodeParseNumber, Pointer: ptr, Message: fmt.Sprintf("line %d: %q is not a finite number", n.Line, n.Value)}
		}
		return f, nil
	case "!!timestamp":
		return nil, Issue{Code: CodeParseTimestamp, Pointer: ptr, Message: fmt.Sprintf("line %d: %q reads as a date; quote it", n.Line, n.Value)}
	}
	return nil, Issue{Code: CodeParseTag, Pointer: ptr, Message: fmt.Sprintf("line %d: the tag %s is not supported", n.Line, n.Tag)}
}

// escape is one JSON Pointer reference token (RFC 6901).
func escape(token string) string {
	return strings.ReplaceAll(strings.ReplaceAll(token, "~", "~0"), "/", "~1")
}

var printer = message.NewPrinter(language.English)

var (
	compileOnce sync.Once
	compiled    *jsonschema.Schema
	compileErr  error
)

// Schema is the compiled v1 schema.
func Schema() (*jsonschema.Schema, error) {
	compileOnce.Do(func() {
		doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(SchemaJSON))
		if err != nil {
			compileErr = err
			return
		}
		c := jsonschema.NewCompiler()
		c.DefaultDraft(jsonschema.Draft2020)
		if err := c.AddResource(SchemaID, doc); err != nil {
			compileErr = err
			return
		}
		compiled, compileErr = c.Compile(SchemaID)
	})
	return compiled, compileErr
}

// Validate checks a parsed document against the schema. Each issue is one failing leaf of the validation, with the
// pointer of the value that fails (for unknown or missing properties: the object holding them).
func Validate(doc any) ([]Issue, error) {
	s, err := Schema()
	if err != nil {
		return nil, err
	}
	err = s.Validate(doc)
	if err == nil {
		return nil, nil
	}
	var ve *jsonschema.ValidationError
	if !errors.As(err, &ve) {
		return nil, err
	}
	var issues []Issue
	seen := map[string]bool{}
	var leaves func(e *jsonschema.ValidationError)
	leaves = func(e *jsonschema.ValidationError) {
		if len(e.Causes) == 0 {
			ptr := pointer(e.InstanceLocation)
			msg := e.ErrorKind.LocalizedString(printer)
			if !seen[ptr+"\x00"+msg] {
				seen[ptr+"\x00"+msg] = true
				issues = append(issues, Issue{Code: CodeSchema, Pointer: ptr, Message: msg})
			}
			return
		}
		for _, c := range e.Causes {
			leaves(c)
		}
	}
	leaves(ve)
	sort.SliceStable(issues, func(i, j int) bool { return issues[i].Pointer < issues[j].Pointer })
	return issues, nil
}

func pointer(tokens []string) string {
	var b strings.Builder
	for _, t := range tokens {
		b.WriteString("/")
		b.WriteString(escape(t))
	}
	return b.String()
}
