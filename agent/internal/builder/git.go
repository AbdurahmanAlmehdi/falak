package builder

import (
	"context"
	"encoding/base64"
	"fmt"
	"io"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// gitEnv returns the environment for git: no prompts, deploy key via GIT_SSH_COMMAND, HTTPS token via
// an http.extraHeader injected with GIT_CONFIG_* (never on the command line or in .git/config).
func gitEnv(repo Repo, secretsDir string) ([]string, error) {
	env := []string{"GIT_TERMINAL_PROMPT=0", "GIT_ASKPASS=/bin/true"}
	if repo.DeployKey != "" {
		key := filepath.Join(secretsDir, "deploy_key")
		k := repo.DeployKey
		if !strings.HasSuffix(k, "\n") {
			k += "\n"
		}
		if err := os.WriteFile(key, []byte(k), 0o600); err != nil {
			return nil, err
		}
		kh := filepath.Join(secretsDir, "known_hosts")
		strict := "accept-new"
		if repo.KnownHosts != "" {
			strict = "yes"
			if err := os.WriteFile(kh, []byte(repo.KnownHosts), 0o600); err != nil {
				return nil, err
			}
		}
		env = append(env, fmt.Sprintf("GIT_SSH_COMMAND=ssh -i %s -o IdentitiesOnly=yes -o BatchMode=yes -o UserKnownHostsFile=%s -o StrictHostKeyChecking=%s", key, kh, strict))
	}
	var cfg []string // key, value, ...
	if repo.Token != "" {
		u, err := url.Parse(repo.URL)
		if err != nil || (u.Scheme != "https" && u.Scheme != "http") {
			return nil, fmt.Errorf("repo.token requires an https repo url")
		}
		user := repo.Username
		if user == "" {
			user = "x-access-token"
		}
		basic := base64.StdEncoding.EncodeToString([]byte(user + ":" + repo.Token))
		cfg = append(cfg, "http.extraHeader", "Authorization: Basic "+basic)
	}
	// Clone, fetch and submodules speak https and ssh only (no file://, ext::); http or git only for a repo that uses it.
	cfg = append(cfg, "protocol.allow", "never", "protocol.https.allow", "always", "protocol.ssh.allow", "always")
	if strings.HasPrefix(repo.URL, "http://") {
		cfg = append(cfg, "protocol.http.allow", "always")
	}
	if strings.HasPrefix(repo.URL, "git://") {
		cfg = append(cfg, "protocol.git.allow", "always")
	}
	env = append(env, fmt.Sprintf("GIT_CONFIG_COUNT=%d", len(cfg)/2))
	for i := 0; i < len(cfg); i += 2 {
		env = append(env, fmt.Sprintf("GIT_CONFIG_KEY_%d=%s", i/2, cfg[i]), fmt.Sprintf("GIT_CONFIG_VALUE_%d=%s", i/2, cfg[i+1]))
	}
	return env, nil
}

type checkout struct {
	Commit string
	Time   time.Time
}

// clone performs a shallow fetch of exactly one revision into dir.
func (b *Builder) clone(ctx context.Context, repo Repo, dir, secretsDir string, out, errw io.Writer) (checkout, error) {
	env, err := gitEnv(repo, secretsDir)
	if err != nil {
		return checkout{}, err
	}
	git := func(args ...string) (runner.Result, error) {
		return runner.Check(ctx, b.Runner, runner.Cmd{Name: "git", Args: append([]string{"-C", dir}, args...), Env: env, Stdout: out, Stderr: errw})
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return checkout{}, err
	}
	if _, err := git("init", "-q"); err != nil {
		return checkout{}, err
	}
	if _, err := git("remote", "add", "origin", repo.URL); err != nil {
		return checkout{}, err
	}
	rev := repo.Commit
	if rev == "" {
		rev = repo.Ref
	}
	if rev == "" {
		rev = "HEAD"
	}
	if _, err := git("fetch", "-q", "--depth", "1", "--no-tags", "origin", rev); err != nil {
		if repo.Commit == "" {
			return checkout{}, err
		}
		// Servers without allowReachableSHA1InWant: fetch the branch history and check out the sha.
		fmt.Fprintf(out, "shallow fetch of %s failed; fetching %s\n", repo.Commit, orHEAD(repo.Ref))
		if _, err := git("fetch", "-q", "--no-tags", "origin", orHEAD(repo.Ref)); err != nil {
			return checkout{}, err
		}
		rev = repo.Commit
	} else {
		rev = "FETCH_HEAD"
	}
	if _, err := git("checkout", "-q", "--detach", rev); err != nil {
		return checkout{}, err
	}
	if _, err := os.Stat(filepath.Join(dir, ".gitmodules")); err == nil {
		if _, err := git("submodule", "update", "-q", "--init", "--recursive", "--depth", "1"); err != nil {
			return checkout{}, err
		}
	}
	res, err := runner.Check(ctx, b.Runner, runner.Cmd{Name: "git", Args: []string{"-C", dir, "log", "-1", "--format=%H %ct"}, Env: env})
	if err != nil {
		return checkout{}, err
	}
	var co checkout
	if f := strings.Fields(string(res.Stdout)); len(f) == 2 {
		co.Commit = f[0]
		if sec, err := strconv.ParseInt(f[1], 10, 64); err == nil {
			co.Time = time.Unix(sec, 0).UTC()
		}
	}
	return co, nil
}

func orHEAD(ref string) string {
	if ref == "" {
		return "HEAD"
	}
	return ref
}
