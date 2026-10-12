package cli

import (
	"context"
	"fmt"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

func cmdDBList(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("db list"), args, 0, 0, ""); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	instances, err := c.DatabaseInstances(ctx)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(instances)
	}
	rows := make([][]string, 0, len(instances))
	for _, d := range instances {
		dbs := "-"
		if d.DatabasesCount != nil {
			dbs = strconv.Itoa(*d.DatabasesCount)
		}
		rows = append(rows, []string{d.ID, d.Name, d.Engine + " " + d.Version, d.Status, dash(d.ServerName), strconv.Itoa(d.MemoryMB), dbs})
	}
	a.table([]string{"ID", "NAME", "ENGINE", "STATUS", "SERVER", "MEMORY_MB", "DATABASES"}, rows)
	return nil
}

func cmdDBDelete(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("db delete")
	confirm := fs.String("confirm", "", "the instance's name, typed to confirm (required)")
	deleteVolume := fs.Bool("delete-volume", false, "also delete the data volume (kept by default)")
	pos, err := a.parse(fs, args, 1, 1, "<instance> --confirm NAME [--delete-volume]")
	if err != nil {
		return err
	}
	if *confirm == "" {
		return usagef("db delete: --confirm NAME is required (the instance's name)")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	d, err := resolveDatabaseInstance(ctx, c, pos[0])
	if err != nil {
		return err
	}
	d, err = c.DeleteDatabaseInstance(ctx, d.ID, *confirm, *deleteVolume)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(d)
	}
	volume := "kept"
	if *deleteVolume {
		volume = "deleted with it"
	}
	fmt.Fprintf(a.Stdout, "%s (%s): %s; data volume %s\n", d.Name, d.ID, d.Status, volume)
	return nil
}

// resolveDatabaseInstance accepts an id or a (unique) name.
func resolveDatabaseInstance(ctx context.Context, c api.API, ref string) (api.DatabaseInstance, error) {
	d, err := c.DatabaseInstance(ctx, ref)
	if err == nil || !api.IsNotFound(err) {
		return d, err
	}
	all, lerr := c.DatabaseInstances(ctx)
	if lerr != nil {
		return d, lerr
	}
	var hits []api.DatabaseInstance
	for _, x := range all {
		if strings.EqualFold(x.Name, ref) {
			hits = append(hits, x)
		}
	}
	switch len(hits) {
	case 0:
		return d, fmt.Errorf("database instance %q not found", ref)
	case 1:
		return hits[0], nil
	}
	return d, fmt.Errorf("database instance name %q is ambiguous (%d matches); use the id", ref, len(hits))
}
