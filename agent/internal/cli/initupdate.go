package cli

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"
)

// `falak init --update`: detection runs again and what it finds that the file doesn't have yet is added, as text
// inserted into the file, so every key, comment, blank line and the order the user wrote stay as they are. Nothing
// is removed or rewritten; a value the code now says otherwise (a PHP version, an engine) is flagged with a comment
// and a note, never changed.

// insertion adds fields to a block mapping of the existing file.
type insertion struct {
	parent *yaml.Node
	fields *omap // new keys, in order
}

type updater struct {
	lines   []string
	inserts map[*yaml.Node]*insertion
	order   []*yaml.Node
	flags   map[int]string // line (1-based) → comment to append
	notes   []string
	units   *yaml.Node // the file's units mapping: new units get a blank line before them
}

func (a *App) initUpdate(dir string, o initOptions, d *detection, doc *omap) error {
	name := ""
	for _, n := range []string{"falak.yaml", "falak.yml"} {
		if st, err := os.Lstat(filepath.Join(dir, n)); err == nil {
			if st.Mode()&os.ModeSymlink != 0 {
				return fmt.Errorf("%s is a symlink: refusing to read or write through it", n)
			}
			name = n
			break
		}
	}
	if name == "" {
		if _, err := os.Lstat(filepath.Join(dir, "falak.json")); err == nil {
			return errors.New("--update edits falak.yaml (YAML keeps comments); falak.json isn't supported")
		}
		return errors.New("no falak.yaml to update: run `falak init` first")
	}
	raw, err := os.ReadFile(filepath.Join(dir, name))
	if err != nil {
		return err
	}
	if v := validateOffline(configFile{Path: name, Display: name, Format: "yaml", Raw: raw}, ""); !v.Valid {
		printIssues(a.Stderr, name, v.Errors)
		return invalid(name, len(v.Errors))
	}
	var root yaml.Node
	if err := yaml.Unmarshal(raw, &root); err != nil || len(root.Content) == 0 || root.Content[0].Kind != yaml.MappingNode {
		return fmt.Errorf("%s: not a YAML mapping", name)
	}
	top := root.Content[0]

	text := strings.TrimSuffix(string(raw), "\n")
	u := &updater{lines: strings.Split(text, "\n"), inserts: map[*yaml.Node]*insertion{}, flags: map[int]string{}}
	newUnits, _ := doc.get("units").(*omap)
	_, existingUnits := mapChild(top, "units")
	if existingUnits == nil || newUnits == nil {
		return fmt.Errorf("%s has no units", name)
	}
	u.units = existingUnits
	renames := u.matchUnits(existingUnits, newUnits)
	renameUnits(newUnits, renames)
	u.merge(existingUnits, newUnits, "/units")

	updated := u.render()
	if !bytes.HasSuffix(updated, []byte("\n")) {
		updated = append(updated, '\n')
	}
	notes := append([]string(nil), u.notes...)
	for _, n := range d.Notes {
		// Detection notes name the detected keys: use the file's.
		if k, rest, ok := strings.Cut(n, ": "); ok && renames[k] != "" {
			n = renames[k] + ": " + rest
		}
		notes = append(notes, n)
	}
	changed := !bytes.Equal(updated, raw) || len(d.Dockerfiles) > 0
	diff := unifiedDiff(string(raw), string(updated), name, name)
	if changed && !bytes.Equal(updated, raw) {
		if v := validateOffline(configFile{Path: name, Display: name, Format: "yaml", Raw: updated}, ""); !v.Valid {
			printIssues(a.Stderr, name+" (updated)", v.Errors)
			return errors.New("the update would make the file invalid; nothing written (please report it)")
		}
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"changed": changed, "diff": diff, "file": string(updated), "notes": notes, "written": changed && o.write})
	}
	for _, n := range notes {
		fmt.Fprintln(a.Stderr, "note:", n)
	}
	if !changed {
		fmt.Fprintf(a.Stdout, "%s has everything the code shows: nothing to add.\n", name)
		return nil
	}
	fmt.Fprint(a.Stdout, diff)
	for _, df := range d.Dockerfiles {
		fmt.Fprintf(a.Stdout, "+ %s (a starter Dockerfile)\n", df.Path)
	}
	if !o.write {
		fmt.Fprintf(a.Stderr, "Not written: run `falak init --update --write` to apply this to %s.\n", name)
		return nil
	}
	for _, df := range d.Dockerfiles {
		if err := writeInRepo(dir, df.Path, []byte(df.Content), false); err != nil {
			return err
		}
	}
	if err := writeInRepo(dir, name, updated, true); err != nil {
		return err
	}
	fmt.Fprintf(a.Stderr, "Updated %s.\n", name)
	return nil
}

// mapChild is the key and value nodes of key in a mapping.
func mapChild(m *yaml.Node, key string) (*yaml.Node, *yaml.Node) {
	if m == nil || m.Kind != yaml.MappingNode {
		return nil, nil
	}
	for i := 0; i+1 < len(m.Content); i += 2 {
		if m.Content[i].Value == key {
			return m.Content[i], m.Content[i+1]
		}
	}
	return nil, nil
}

func scalarOf(m *yaml.Node, key string) string {
	_, v := mapChild(m, key)
	if v != nil && v.Kind == yaml.ScalarNode {
		return v.Value
	}
	return ""
}

// unitIdentity is what makes a detected unit the same as one of the file under another key: an app or compose unit
// by its root, a database by its engine, Redis by itself.
func unitIdentity(typ, root, engine string) string {
	switch typ {
	case "app", "compose", "function":
		if root == "" {
			root = "."
		}
		return typ + ":" + strings.TrimSuffix(root, "/")
	case "database":
		return "database:" + engine
	case "redis":
		if engine == "" {
			engine = "redis"
		}
		return "redis:" + engine
	}
	return typ
}

// matchUnits maps each detected unit key to the file's key for the same unit (renamed in the file, or taken by
// another unit).
func (u *updater) matchUnits(existing *yaml.Node, detected *omap) map[string]string {
	byIdentity := map[string]string{}
	taken := map[string]bool{}
	for i := 0; i+1 < len(existing.Content); i += 2 {
		k, v := existing.Content[i].Value, existing.Content[i+1]
		taken[k] = true
		id := unitIdentity(scalarOf(v, "type"), scalarOf(v, "root"), scalarOf(v, "engine"))
		if _, dup := byIdentity[id]; !dup {
			byIdentity[id] = k
		}
	}
	renames := map[string]string{}
	used := map[string]bool{}
	for _, f := range detected.fields {
		m, _ := f.val.(*omap)
		if m == nil {
			continue
		}
		id := unitIdentity(str(m.get("type")), str(m.get("root")), str(m.get("engine")))
		if k, ok := byIdentity[id]; ok && !used[k] {
			used[k] = true
			if k != f.key {
				renames[f.key] = k
			}
			continue
		}
		if taken[f.key] {
			// Another unit of the file has this key: the new one gets a free one.
			base, k := f.key, f.key
			for i := 2; taken[k]; i++ {
				k = fmt.Sprintf("%s-%d", base, i)
			}
			taken[k] = true
			renames[f.key] = k
		}
	}
	return renames
}

// renameUnits renames detected units and every reference to them (${{ key.X }}, replace_with).
func renameUnits(units *omap, renames map[string]string) {
	if len(renames) == 0 {
		return
	}
	for _, f := range units.fields {
		if to, ok := renames[f.key]; ok {
			f.key = to
		}
	}
	var walk func(m *omap)
	walk = func(m *omap) {
		for _, f := range m.fields {
			switch v := f.val.(type) {
			case *omap:
				walk(v)
			case string:
				if f.key == "replace_with" {
					if to, ok := renames[v]; ok {
						f.val = to
					}
					continue
				}
				f.val = configRefRe.ReplaceAllStringFunc(v, func(s string) string {
					sm := configRefRe.FindStringSubmatch(s)
					if to, ok := renames[sm[2]]; ok {
						return sm[1] + to + sm[3]
					}
					return s
				})
			}
		}
	}
	walk(units)
}

var configRefRe = regexp.MustCompile(`(\$\{\{\s*)([A-Za-z0-9][A-Za-z0-9 _.\-]*?)(\.[A-Za-z_][A-Za-z0-9_]*\s*\}\})`)

// merge collects what detected has that the file's mapping lacks, recursively, and flags values that differ.
func (u *updater) merge(existing *yaml.Node, detected *omap, ptr string) {
	for _, f := range detected.fields {
		k, v := mapChild(existing, f.key)
		path := ptr + "/" + escapeToken(f.key)
		if k == nil {
			u.add(existing, f, path)
			continue
		}
		switch nv := f.val.(type) {
		case *omap:
			if v.Kind == yaml.MappingNode {
				u.merge(v, nv, path)
			} else {
				u.notes = append(u.notes, fmt.Sprintf("%s: the code suggests a map here; kept the file's value", path))
			}
		default:
			if v.Kind != yaml.ScalarNode {
				continue
			}
			want := fmt.Sprint(nv)
			if want == v.Value || strings.Contains(path, "/env/") {
				continue // env values are the file's to choose
			}
			why := f.comment
			if why == "" {
				why = "detected"
			}
			u.notes = append(u.notes, fmt.Sprintf("%s: the code now says %q (%s); the file says %q: kept", path, want, why, v.Value))
			if v.LineComment == "" && v.Style&(yaml.LiteralStyle|yaml.FoldedStyle) == 0 && v.Line > 0 && v.Line <= len(u.lines) {
				u.flags[v.Line] = fmt.Sprintf("falak init: the code now says %q", want)
			}
		}
	}
}

func (u *updater) add(parent *yaml.Node, f *ofield, path string) {
	if parent.Kind != yaml.MappingNode || parent.Style&yaml.FlowStyle != 0 || len(parent.Content) == 0 {
		u.notes = append(u.notes, fmt.Sprintf("%s: detected, but its parent is written inline; add it by hand", path))
		return
	}
	ins := u.inserts[parent]
	if ins == nil {
		ins = &insertion{parent: parent, fields: newOmap()}
		u.inserts[parent] = ins
		u.order = append(u.order, parent)
	}
	ins.fields.fields = append(ins.fields.fields, f)
}

func indentOf(line string) int { return len(line) - len(strings.TrimLeft(line, " ")) }

// end is the last line (1-based) of a block mapping's entries: up to the next line indented less than its keys
// (blank lines at the end left out).
func (u *updater) end(m *yaml.Node) int {
	col := m.Content[0].Column - 1
	last := m.Content[0].Line
	for i := last + 1; i <= len(u.lines); i++ {
		l := u.lines[i-1]
		if strings.TrimSpace(l) == "" {
			continue
		}
		if indentOf(l) < col {
			break
		}
		last = i
	}
	return last
}

func (u *updater) render() []byte {
	type block struct {
		after int
		text  []string
	}
	var blocks []block
	for _, parent := range u.order {
		ins := u.inserts[parent]
		frag, err := renderFragment(ins.fields)
		if err != nil {
			continue
		}
		pad := strings.Repeat(" ", parent.Content[0].Column-1)
		var lines []string
		for _, l := range strings.Split(strings.TrimRight(string(frag), "\n"), "\n") {
			// Units are separated by a blank line, as init writes them.
			if parent == u.units && indentOf(l) == 0 && l != "" && !strings.HasPrefix(l, "#") {
				lines = append(lines, "")
			}
			if l == "" {
				lines = append(lines, "")
			} else {
				lines = append(lines, pad+l)
			}
		}
		blocks = append(blocks, block{after: u.end(parent), text: lines})
	}
	sort.SliceStable(blocks, func(i, j int) bool { return blocks[i].after > blocks[j].after })
	lines := append([]string(nil), u.lines...)
	for n, c := range u.flags {
		lines[n-1] += " # " + c
	}
	for _, b := range blocks {
		rest := append([]string(nil), lines[b.after:]...)
		lines = append(append(lines[:b.after], b.text...), rest...)
	}
	return []byte(strings.Join(lines, "\n"))
}

// renderFragment writes fields as YAML (indented 2, comments kept).
func renderFragment(fields *omap) ([]byte, error) {
	var buf bytes.Buffer
	enc := yaml.NewEncoder(&buf)
	enc.SetIndent(2)
	if err := enc.Encode(fields.node()); err != nil {
		return nil, err
	}
	if err := enc.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// ---- unified diff ----------------------------------------------------------------------------------------------

// unifiedDiff is a unified diff (3 lines of context) of two texts, "" when equal.
func unifiedDiff(a, b, nameA, nameB string) string {
	if a == b {
		return ""
	}
	x := strings.Split(strings.TrimSuffix(a, "\n"), "\n")
	y := strings.Split(strings.TrimSuffix(b, "\n"), "\n")
	// LCS table (files are small).
	n, m := len(x), len(y)
	lcs := make([][]int, n+1)
	for i := range lcs {
		lcs[i] = make([]int, m+1)
	}
	for i := n - 1; i >= 0; i-- {
		for j := m - 1; j >= 0; j-- {
			if x[i] == y[j] {
				lcs[i][j] = lcs[i+1][j+1] + 1
			} else {
				lcs[i][j] = max(lcs[i+1][j], lcs[i][j+1])
			}
		}
	}
	type op struct {
		kind byte // ' ', '-', '+'
		text string
		ai   int // line in a (0-based) for ' ' and '-'
		bi   int // line in b for ' ' and '+'
	}
	var ops []op
	i, j := 0, 0
	for i < n || j < m {
		switch {
		case i < n && j < m && x[i] == y[j]:
			ops = append(ops, op{' ', x[i], i, j})
			i++
			j++
		case i < n && (j == m || lcs[i+1][j] >= lcs[i][j+1]):
			ops = append(ops, op{'-', x[i], i, j})
			i++
		default:
			ops = append(ops, op{'+', y[j], i, j})
			j++
		}
	}
	const ctx = 3
	var out strings.Builder
	fmt.Fprintf(&out, "--- %s\n+++ %s\n", nameA, nameB)
	for k := 0; k < len(ops); {
		if ops[k].kind == ' ' {
			k++
			continue
		}
		start := max(k-ctx, 0)
		end := k
		for end < len(ops) {
			if ops[end].kind != ' ' {
				end++
				continue
			}
			run := end
			for run < len(ops) && ops[run].kind == ' ' {
				run++
			}
			if run-end > 2*ctx || run == len(ops) {
				end = min(end+ctx, len(ops))
				break
			}
			end = run
		}
		aStart, bStart, aLen, bLen := ops[start].ai, ops[start].bi, 0, 0
		for _, o := range ops[start:end] {
			if o.kind != '+' {
				aLen++
			}
			if o.kind != '-' {
				bLen++
			}
		}
		fmt.Fprintf(&out, "@@ -%d,%d +%d,%d @@\n", aStart+1, aLen, bStart+1, bLen)
		for _, o := range ops[start:end] {
			out.WriteByte(o.kind)
			out.WriteString(o.text)
			out.WriteByte('\n')
		}
		k = end
	}
	return out.String()
}
