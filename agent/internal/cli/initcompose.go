package cli

import (
	"fmt"
	"path"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// composeNames are the compose files Falak reads by default first (compose.yaml, else docker-compose.yml).
var composeNames = []string{"compose.yaml", "compose.yml", "docker-compose.yaml", "docker-compose.yml"}

func composeFile(repo fsRepo) string {
	for _, n := range composeNames {
		if repo.exists(n) {
			return n
		}
	}
	return ""
}

// Database images Falak runs as managed instances (Sites' RepoComposeInspection: official images only for Redis).
var composeEngines = []struct {
	engine string
	re     *regexp.Regexp
}{
	{"postgresql", regexp.MustCompile(`(^|/)(postgres|postgis|postgresql)(:|@|$)`)},
	{"mysql", regexp.MustCompile(`(^|/)(mysql|percona)(:|@|$)`)},
	{"mariadb", regexp.MustCompile(`(^|/)mariadb(:|@|$)`)},
	{"redis", regexp.MustCompile(`^(docker\.io/)?(library/)?redis(:|@|$)`)},
	{"valkey", regexp.MustCompile(`^(docker\.io/)?valkey/valkey(:|@|$)`)},
}

// devTools are local development helpers, not part of what runs in production.
var devTools = map[string]bool{"mailpit": true, "mailhog": true, "adminer": true, "phpmyadmin": true, "pgadmin4": true, "redis-commander": true, "selenium": true, "standalone-chrome": true}

type composeService struct {
	Image string         `yaml:"image"`
	Build any            `yaml:"build"`
	Ports []any          `yaml:"ports"`
	Extra map[string]any `yaml:",inline"`
}

func imageEngine(image string) string {
	for _, e := range composeEngines {
		if e.re.MatchString(image) {
			return e.engine
		}
	}
	return ""
}

// imageTag is the tag of an image reference ("postgres:16-alpine@sha256:…" → "16-alpine").
func imageTag(image string) string {
	image, _, _ = strings.Cut(image, "@")
	slash := strings.LastIndex(image, "/")
	if i := strings.LastIndex(image, ":"); i > slash {
		return image[i+1:]
	}
	return ""
}

var majorRe = regexp.MustCompile(`^([0-9]+(?:\.[0-9]+)?)`)

// supportedVersion maps an image tag to a version Falak offers ("16-alpine" → "16", "8.0.39" → "8.0").
func supportedVersion(engine, tag string) string {
	m := majorRe.FindStringSubmatch(tag)
	if m == nil {
		return ""
	}
	for _, v := range supportedDBMajor[engine] {
		if v == m[1] || strings.HasPrefix(m[1], v+".") {
			return v
		}
	}
	return ""
}

func containerPort(p any) int {
	switch t := p.(type) {
	case int:
		return t
	case string:
		t, _, _ = strings.Cut(t, "/")
		parts := strings.Split(t, ":")
		n, err := strconv.Atoi(parts[len(parts)-1])
		if err == nil {
			return n
		}
	case map[string]any:
		if n, ok := t["target"].(int); ok {
			return n
		}
	}
	return 0
}

// composeOffer is a database container offered as a Falak database or Redis unit.
type composeOffer struct {
	service, image string
	unit           *detectedUnit
}

// detectCompose makes a compose unit when the compose file runs the app (not only its development databases, and
// not Laravel Sail). Its database containers become database and redis units (replace_with).
func (d *detection) detectCompose(repo fsRepo, file string) (bool, error) {
	var doc struct {
		Services map[string]composeService `yaml:"services"`
	}
	if err := yaml.Unmarshal([]byte(repo.read(file)), &doc); err != nil {
		return false, fmt.Errorf("%s: %w", file, err)
	}
	names := make([]string, 0, len(doc.Services))
	for n := range doc.Services {
		names = append(names, n)
	}
	sort.Strings(names)
	var apps, dbs []string
	for _, n := range names {
		s := doc.Services[n]
		if strings.HasPrefix(path.Base(s.Image), "sail-") || strings.Contains(fmt.Sprint(s.Build), "vendor/laravel/sail") {
			return false, nil // Laravel Sail: a local environment, not the deployment
		}
		switch base, _, _ := strings.Cut(path.Base(s.Image), ":"); {
		case imageEngine(s.Image) != "":
			dbs = append(dbs, n)
		case devTools[base]:
			d.Notes = append(d.Notes, fmt.Sprintf("%s (%s) looks like a development tool: it runs with the stack unless a profile keeps it out", n, s.Image))
		default:
			apps = append(apps, n)
		}
	}
	if len(apps) == 0 {
		return false, nil
	}
	d.Kind = "compose"
	u := &detectedUnit{Key: "web", Type: "compose", fields: newOmap(), Reasons: []string{file}}
	u.fields.set("type", "compose", "detected: "+file)
	if file != "compose.yaml" && file != "docker-compose.yml" {
		u.fields.set("files", []any{file}, "detected")
	}
	d.Units = append(d.Units, u)
	services := newOmap()
	for _, n := range apps {
		for _, p := range doc.Services[n].Ports {
			if port := containerPort(p); port > 0 {
				services.sub(n, "").set("port", port, "detected: ports of "+n+" in "+file)
				break
			}
		}
	}
	taken := map[string]bool{u.Key: true}
	for _, n := range dbs {
		s := doc.Services[n]
		engine := imageEngine(s.Image)
		fallback := "db"
		if engine == "redis" || engine == "valkey" {
			fallback = "redis"
		}
		base := unitKey(n, fallback)
		key := base
		for i := 2; taken[key]; i++ {
			key = fmt.Sprintf("%s-%d", base, i)
		}
		taken[key] = true
		db := &detectedUnit{Key: key, fields: newOmap(), Reasons: []string{"image " + s.Image + " in " + file}}
		why := "detected: image " + s.Image + " of " + n + " in " + file
		if engine == "redis" || engine == "valkey" {
			db.Type, db.Engine = "redis", engine
			db.fields.set("type", "redis", why)
			if engine == "valkey" {
				db.fields.set("engine", "valkey", "")
			}
		} else {
			db.Type, db.Engine = "database", engine
			db.fields.set("type", "database", "")
			db.fields.set("engine", engine, why)
		}
		if tag := imageTag(s.Image); tag != "" && tag != "latest" {
			if v := supportedVersion(engine, tag); v != "" {
				db.fields.set("version", v, "detected: tag "+tag)
			} else {
				d.Notes = append(d.Notes, fmt.Sprintf("%s: %s %s isn't offered; Falak uses its newest", key, engine, tag))
			}
		}
		services.sub(n, "").set("replace_with", key, "run "+n+" as a Falak-managed instance")
		d.Units = append(d.Units, db)
		d.offers = append(d.offers, composeOffer{service: n, image: s.Image, unit: db})
	}
	if !services.empty() {
		u.fields.set("services", services, "")
	}
	return true, nil
}

// declineOffer keeps a database container in the stack instead of a Falak unit.
func (d *detection) declineOffer(o composeOffer) {
	for i, u := range d.Units {
		if u == o.unit {
			d.Units = append(d.Units[:i], d.Units[i+1:]...)
			break
		}
	}
	for _, u := range d.Units {
		if u.Type != "compose" {
			continue
		}
		services, _ := u.fields.get("services").(*omap)
		if services == nil {
			continue
		}
		if s, _ := services.get(o.service).(*omap); s != nil {
			s.delete("replace_with")
			if s.empty() {
				services.delete(o.service)
			}
		}
		if services.empty() {
			u.fields.delete("services")
		}
	}
}
