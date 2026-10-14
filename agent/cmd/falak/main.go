// Command falak is the Falak CLI: it talks to the control-plane public REST API with a Sanctum token.
//
//	falak login --url https://falak.example.com
//	falak sites list
//	falak deploy my-site --wait
//	falak console my-site
//
// CI: set FALAK_URL and FALAK_TOKEN; add --json for machine-readable output.
package main

import (
	"bufio"
	"context"
	"os"
	"os/exec"
	"os/signal"
	"runtime"
	"syscall"

	"github.com/OthmanHaba/falak/agent/internal/cli"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	app := &cli.App{
		Stdout:      os.Stdout,
		Stderr:      os.Stderr,
		Stdin:       os.Stdin,
		Getenv:      os.Getenv,
		Version:     version.Version,
		Interactive: isTerminal(os.Stdin),
		Terminal:    cli.StdTerminal(),
		ReadSecret:  readSecret,
		Exec:        execReplace,
		Open:        openBrowser,
	}
	os.Exit(app.Run(ctx, os.Args[1:]))
}

func isTerminal(f *os.File) bool {
	st, err := f.Stat()
	return err == nil && st.Mode()&os.ModeCharDevice != 0
}

// readSecret reads a line from the terminal with echo disabled (via stty; stdlib only).
func readSecret() (string, error) {
	stty := func(arg string) {
		c := exec.Command("stty", arg)
		c.Stdin = os.Stdin
		_ = c.Run()
	}
	stty("-echo")
	defer stty("echo")
	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil && line == "" {
		return "", err
	}
	return line, nil
}

// execReplace replaces the process with argv (ssh keeps the TTY and exit status).
func execReplace(argv []string) error {
	path, err := exec.LookPath(argv[0])
	if err != nil {
		return err
	}
	return syscall.Exec(path, argv, os.Environ())
}

func openBrowser(url string) error {
	name := "xdg-open"
	if runtime.GOOS == "darwin" {
		name = "open"
	}
	c := exec.Command(name, url)
	c.Stdout, c.Stderr = nil, nil
	return c.Start()
}
