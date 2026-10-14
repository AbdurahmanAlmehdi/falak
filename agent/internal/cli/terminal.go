package cli

import (
	"os"

	"golang.org/x/term"
)

// StdTerminal is the process's terminal for `falak console`, or nil when stdin isn't one.
func StdTerminal() Terminal {
	in := int(os.Stdin.Fd())
	if !term.IsTerminal(in) {
		return nil
	}
	return stdTerminal{in: in, out: int(os.Stdout.Fd())}
}

type stdTerminal struct{ in, out int }

func (t stdTerminal) MakeRaw() (func(), error) {
	st, err := term.MakeRaw(t.in)
	if err != nil {
		return nil, err
	}
	return func() { _ = term.Restore(t.in, st) }, nil
}

func (t stdTerminal) Size() (int, int, error) {
	if w, h, err := term.GetSize(t.out); err == nil {
		return w, h, nil
	}
	return term.GetSize(t.in)
}

func (t stdTerminal) Resized() (<-chan struct{}, func()) { return windowChanges() }
