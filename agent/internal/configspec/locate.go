package configspec

import (
	"bytes"
	"regexp"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// Positions finds line and column (1-based) of the values of a file by JSON Pointer. yaml.v3 reads JSON too, so one
// lookup serves falak.yaml and falak.json.
type Positions struct {
	root *yaml.Node
}

// NewPositions parses data for lookups; a file that doesn't parse yields no positions.
func NewPositions(data []byte) *Positions {
	data = bytes.TrimPrefix(data, []byte("\xef\xbb\xbf"))
	var doc yaml.Node
	if err := yaml.Unmarshal(data, &doc); err != nil || len(doc.Content) == 0 {
		return &Positions{}
	}
	return &Positions{root: doc.Content[0]}
}

// Locate returns the position of the value at pointer, or of the key property inside it when property is set (an
// unknown property). 0, 0 when unknown.
func (p *Positions) Locate(pointer, property string) (line, column int) {
	if p == nil || p.root == nil {
		return 0, 0
	}
	n, key := p.root, (*yaml.Node)(nil)
	if pointer != "" {
		for _, tok := range strings.Split(strings.TrimPrefix(pointer, "/"), "/") {
			tok = strings.ReplaceAll(strings.ReplaceAll(tok, "~1", "/"), "~0", "~")
			k, v := child(n, tok)
			if v == nil {
				return pos(key, n)
			}
			n, key = v, k
		}
	}
	if property != "" {
		if k, _ := child(n, property); k != nil {
			return k.Line, k.Column
		}
	}
	return pos(key, n)
}

func pos(key, n *yaml.Node) (int, int) {
	// A block mapping or sequence starts on its first entry: point at its key instead.
	if key != nil && (n.Kind == yaml.MappingNode || n.Kind == yaml.SequenceNode) && n.Style&yaml.FlowStyle == 0 {
		return key.Line, key.Column
	}
	return n.Line, n.Column
}

// child is a mapping's key and value (explicit keys, then << merges) or a sequence's item.
func child(n *yaml.Node, tok string) (key, val *yaml.Node) {
	for n.Kind == yaml.AliasNode {
		n = n.Alias
	}
	switch n.Kind {
	case yaml.MappingNode:
		var merges []*yaml.Node
		for i := 0; i+1 < len(n.Content); i += 2 {
			k, v := n.Content[i], n.Content[i+1]
			if k.ShortTag() == "!!merge" {
				merges = append(merges, v)
				continue
			}
			if k.Value == tok {
				return k, deref(v)
			}
		}
		for _, m := range merges {
			m = deref(m)
			if m.Kind == yaml.SequenceNode {
				for _, c := range m.Content {
					if k, v := child(deref(c), tok); v != nil {
						return k, v
					}
				}
				continue
			}
			if k, v := child(m, tok); v != nil {
				return k, v
			}
		}
	case yaml.SequenceNode:
		if i, err := strconv.Atoi(tok); err == nil && i >= 0 && i < len(n.Content) {
			return nil, deref(n.Content[i])
		}
	}
	return nil, nil
}

func deref(n *yaml.Node) *yaml.Node {
	for n != nil && n.Kind == yaml.AliasNode {
		n = n.Alias
	}
	return n
}

var lineInMessage = regexp.MustCompile(`\bline (\d+)\b`)

// MessageLine is the line a reader's message names ("yaml: line 3: …", "line 7: \"x\" is defined twice"), or 0.
func MessageLine(msg string) int {
	if m := lineInMessage.FindStringSubmatch(msg); m != nil {
		n, _ := strconv.Atoi(m[1])
		return n
	}
	return 0
}
