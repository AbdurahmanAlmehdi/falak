package cli

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// secretScope is where `falak secret` reads and writes: the project, or one of its environments with -e.
type secretScope struct {
	scope, id, label string
}

func (a *App) secretScope(ctx context.Context, c api.API, project, env string) (secretScope, error) {
	id, err := a.requireProject(ctx, c, project, "")
	if err != nil {
		return secretScope{}, err
	}
	if env == "" {
		return secretScope{"project", id, "project"}, nil
	}
	projects, err := c.Projects(ctx)
	if err != nil {
		return secretScope{}, err
	}
	for _, p := range projects {
		if p.ID != id {
			continue
		}
		var names []string
		for _, e := range p.Environments {
			if strings.EqualFold(e.Slug, env) || strings.EqualFold(e.Name, env) || e.ID == env {
				return secretScope{"environment", e.ID, "environment " + e.Name}, nil
			}
			names = append(names, e.Slug)
		}
		return secretScope{}, usagef("no environment %q in the project (environments: %s)", env, strings.Join(names, ", "))
	}
	return secretScope{}, fmt.Errorf("no project %s", id)
}

func cmdSecretList(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("secret list")
	var project, env string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	if _, err := a.parse(fs, args, 0, 0, "[-p PROJECT] [-e ENV]"); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := a.secretScope(ctx, c, project, env)
	if err != nil {
		return err
	}
	list, err := c.Secrets(ctx, s.scope, s.id)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(list)
	}
	if len(list) == 0 {
		fmt.Fprintf(a.Stdout, "No secrets in the %s.\n", s.label)
		return nil
	}
	rows := make([][]string, 0, len(list))
	for _, x := range list {
		rows = append(rows, []string{x.Name, x.Kind, strconv.Itoa(x.CurrentVersion), dash(x.UpdatedAt)})
	}
	a.table([]string{"NAME", "KIND", "VERSION", "UPDATED"}, rows)
	return nil
}

func cmdSecretSet(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("secret set")
	var project, env string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	fromEnv := fs.String("from-env", "", "import every KEY=VALUE line of a .env file")
	pos, err := a.parse(fs, args, 0, 1, "<NAME> [-p PROJECT] [-e ENV] | --from-env FILE")
	if err != nil {
		return err
	}
	if (len(pos) == 1) == (*fromEnv != "") {
		return usagef("secret set: give a NAME or --from-env FILE (one of them)")
	}
	var values map[string]string
	var order []string
	if *fromEnv != "" {
		if values, order, err = readDotenv(a.abs(*fromEnv)); err != nil {
			return err
		}
		if len(order) == 0 {
			return usagef("%s has no KEY=VALUE lines", *fromEnv)
		}
	} else {
		v, err := a.readSecretValue(pos[0])
		if err != nil {
			return err
		}
		values, order = map[string]string{pos[0]: v}, []string{pos[0]}
	}

	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := a.secretScope(ctx, c, project, env)
	if err != nil {
		return err
	}
	existing, err := c.Secrets(ctx, s.scope, s.id)
	if err != nil {
		return err
	}
	ids := make(map[string]string, len(existing))
	for _, x := range existing {
		ids[x.Name] = x.ID
	}
	var done []api.Secret
	for _, name := range order {
		var sec api.Secret
		verb := "updated"
		if id, ok := ids[name]; ok {
			sec, err = c.SetSecretValue(ctx, id, values[name])
		} else {
			verb = "created"
			sec, err = c.CreateSecret(ctx, s.scope, s.id, name, values[name])
		}
		if err != nil {
			return fmt.Errorf("%s: %w", name, err)
		}
		done = append(done, sec)
		if !a.jsonOut {
			fmt.Fprintf(a.Stdout, "%s: %s in the %s (version %d)\n", name, verb, s.label, sec.CurrentVersion)
		}
	}
	if a.jsonOut {
		return a.printJSON(done)
	}
	return nil
}

func cmdSecretDelete(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("secret delete")
	var project, env string
	envFlag(fs, &env)
	projectFlag(fs, &project)
	yes := fs.Bool("yes", false, "don't ask for confirmation")
	pos, err := a.parse(fs, args, 1, 1, "<NAME> [-p PROJECT] [-e ENV] --yes")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	s, err := a.secretScope(ctx, c, project, env)
	if err != nil {
		return err
	}
	list, err := c.Secrets(ctx, s.scope, s.id)
	if err != nil {
		return err
	}
	for _, x := range list {
		if x.Name != pos[0] {
			continue
		}
		if !*yes {
			if !a.Interactive {
				return usagef("secret delete: pass --yes to delete %s without a prompt", x.Name)
			}
			fmt.Fprintf(a.Stderr, "Delete %s from the %s? Deployments that reference it will fail. [y/N] ", x.Name, s.label)
			line, _ := bufio.NewReader(a.Stdin).ReadString('\n')
			if ans := strings.ToLower(strings.TrimSpace(line)); ans != "y" && ans != "yes" {
				return errors.New("not deleted")
			}
		}
		if err := c.DeleteSecret(ctx, x.ID); err != nil {
			return err
		}
		fmt.Fprintf(a.Stdout, "%s: deleted from the %s\n", x.Name, s.label)
		return nil
	}
	return fmt.Errorf("no secret %s in the %s", pos[0], s.label)
}

// readSecretValue takes the value from a hidden prompt on a terminal, else all of stdin (one trailing newline
// dropped). Never from an argument: it would land in the shell history.
func (a *App) readSecretValue(name string) (string, error) {
	var v string
	if a.Interactive && a.ReadSecret != nil {
		fmt.Fprintf(a.Stderr, "Value for %s: ", name)
		line, err := a.ReadSecret()
		fmt.Fprintln(a.Stderr)
		if err != nil {
			return "", err
		}
		v = line
	} else {
		b, err := io.ReadAll(a.Stdin)
		if err != nil {
			return "", err
		}
		v = strings.TrimSuffix(strings.TrimSuffix(string(b), "\n"), "\r")
	}
	if v == "" {
		return "", usagef("secret set: empty value for %s (type it at the prompt or pipe it on stdin)", name)
	}
	return v, nil
}

// readDotenv parses KEY=VALUE lines: blank lines and # comments skipped, `export ` allowed, one pair of matching
// quotes removed. ponytail: no escapes or multi-line values; pipe those through `falak secret set NAME`.
func readDotenv(path string) (map[string]string, []string, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, nil, err
	}
	values := map[string]string{}
	var order []string
	for i, line := range strings.Split(string(b), "\n") {
		line = strings.TrimSpace(strings.TrimSuffix(line, "\r"))
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(strings.TrimPrefix(line, "export "), "=")
		k = strings.TrimSpace(k)
		if !ok || k == "" {
			return nil, nil, fmt.Errorf("%s:%d: not KEY=VALUE", path, i+1)
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') && v[len(v)-1] == v[0] {
			v = v[1 : len(v)-1]
		}
		if v == "" {
			continue // an empty value isn't a secret; the API would refuse it
		}
		if _, seen := values[k]; !seen {
			order = append(order, k)
		}
		values[k] = v
	}
	return values, order, nil
}
