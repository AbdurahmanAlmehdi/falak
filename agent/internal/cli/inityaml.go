package cli

import (
	"bytes"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// omap is an ordered map with a comment per entry: the shape `falak init` builds before writing YAML.
type omap struct {
	fields []*ofield
	foot   string // comment lines after the last entry (without "# ")
}

type ofield struct {
	key     string
	val     any // string, int, bool, *omap, []any (of string or *omap)
	comment string
}

func newOmap() *omap { return &omap{} }

func (m *omap) find(key string) *ofield {
	for _, f := range m.fields {
		if f.key == key {
			return f
		}
	}
	return nil
}

// set adds or replaces key (keeping its place); a non-empty comment replaces the old one.
func (m *omap) set(key string, val any, comment string) *omap {
	if f := m.find(key); f != nil {
		f.val = val
		if comment != "" {
			f.comment = comment
		}
		return m
	}
	m.fields = append(m.fields, &ofield{key: key, val: val, comment: comment})
	return m
}

// setDefault sets key only when it is absent (for --full defaults).
func (m *omap) setDefault(key string, val any, comment string) {
	if m.find(key) == nil {
		m.set(key, val, comment)
	}
}

// sub returns the map at key, creating it (with comment) when absent.
func (m *omap) sub(key, comment string) *omap {
	if f := m.find(key); f != nil {
		if s, ok := f.val.(*omap); ok {
			if comment != "" && f.comment == "" {
				f.comment = comment
			}
			return s
		}
	}
	s := newOmap()
	m.set(key, s, comment)
	return s
}

func (m *omap) has(key string) bool { return m.find(key) != nil }

func (m *omap) get(key string) any {
	if f := m.find(key); f != nil {
		return f.val
	}
	return nil
}

func (m *omap) delete(key string) {
	for i, f := range m.fields {
		if f.key == key {
			m.fields = append(m.fields[:i], m.fields[i+1:]...)
			return
		}
	}
}

func (m *omap) empty() bool { return len(m.fields) == 0 }

// node renders the map as a yaml.v3 node: comments of scalars go after the value, comments of maps and lists after
// the key.
func (m *omap) node() *yaml.Node {
	n := &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}
	for _, f := range m.fields {
		k := &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: f.key}
		v := valueNode(f.val)
		if f.comment != "" {
			if v.Kind == yaml.ScalarNode || v.Style&yaml.FlowStyle != 0 {
				v.LineComment = "# " + f.comment
			} else {
				k.LineComment = "# " + f.comment
			}
		}
		n.Content = append(n.Content, k, v)
	}
	if m.foot != "" && len(n.Content) > 0 {
		var lines []string
		for _, l := range strings.Split(m.foot, "\n") {
			lines = append(lines, "# "+l)
		}
		n.Content[len(n.Content)-1].FootComment = strings.Join(lines, "\n")
	}
	return n
}

func valueNode(v any) *yaml.Node {
	switch t := v.(type) {
	case *omap:
		return t.node()
	case []any:
		n := &yaml.Node{Kind: yaml.SequenceNode, Tag: "!!seq"}
		flow := true
		for _, x := range t {
			c := valueNode(x)
			if c.Kind != yaml.ScalarNode {
				flow = false
			}
			n.Content = append(n.Content, c)
		}
		if flow {
			n.Style = yaml.FlowStyle
		}
		return n
	case []string:
		l := make([]any, len(t))
		for i, s := range t {
			l[i] = s
		}
		return valueNode(l)
	case int:
		return &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!int", Value: strconv.Itoa(t)}
	case bool:
		return &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!bool", Value: strconv.FormatBool(t)}
	case string:
		n := &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: t}
		if bytes.ContainsRune([]byte(t), '\n') {
			n.Style = yaml.LiteralStyle
		}
		return n
	}
	return &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!null", Value: "null"}
}

// renderYAML writes the document with a head comment.
func renderYAML(doc *omap, head string) ([]byte, error) {
	foot := doc.foot
	doc.foot = ""
	root := doc.node()
	doc.foot = foot
	root.HeadComment = head
	if foot != "" {
		root.FootComment = "# " + strings.ReplaceAll(foot, "\n", "\n# ")
	}
	var buf bytes.Buffer
	enc := yaml.NewEncoder(&buf)
	enc.SetIndent(2)
	if err := enc.Encode(&yaml.Node{Kind: yaml.DocumentNode, Content: []*yaml.Node{root}}); err != nil {
		return nil, err
	}
	if err := enc.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
