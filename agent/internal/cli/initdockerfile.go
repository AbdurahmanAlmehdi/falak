package cli

import (
	"fmt"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

// sniffLanguage names a language builder.Detect doesn't build natively, with the file that shows it.
func sniffLanguage(r fsRepo) (lang, evidence string) {
	for _, c := range []struct{ file, lang string }{
		{"go.mod", "go"}, {"requirements.txt", "python"}, {"pyproject.toml", "python"}, {"Pipfile", "python"},
		{"Gemfile", "ruby"}, {"Cargo.toml", "rust"}, {"pom.xml", "java"}, {"build.gradle.kts", "kotlin"},
		{"build.gradle", "java"}, {"mix.exs", "elixir"},
	} {
		if r.exists(c.file) {
			return c.lang, c.file
		}
	}
	if m, _ := filepath.Glob(filepath.Join(r.dir, "*.csproj")); len(m) > 0 {
		return "dotnet", filepath.Base(m[0])
	}
	return "", ""
}

// defaultPorts are the ports starter Dockerfiles (and Railpack images) listen on, through $PORT.
var defaultPorts = map[string]int{"go": 8080, "python": 8000, "ruby": 3000, "rust": 8080, "java": 8080, "kotlin": 8080, "elixir": 4000, "dotnet": 8080}

func unsupportedApp(r fsRepo, rel string, u *detectedUnit, lang, evidence string, opt detectOptions, d *detection) (*detectedUnit, error) {
	f := u.fields
	port := defaultPorts[lang]
	u.Runtime = "docker"
	switch {
	case opt.railpack:
		f.set("runtime", "docker", fmt.Sprintf("detected: %s (%s); no Dockerfile: built with Railpack", lang, evidence))
		f.set("port", port, "Railpack images listen on $PORT; set it if the app uses another port")
		f.sub("build", "").set("mode", "docker", "the builder needs railpack installed")
		u.Reasons = append(u.Reasons, lang, "railpack")
	case opt.dockerfile:
		content, ok := starterDockerfile(lang, r)
		if !ok {
			return nil, fmt.Errorf("%s (%s): no starter Dockerfile for %s; write one and run `falak init` again", relOrRoot(rel), evidence, lang)
		}
		d.Dockerfile = &starterFile{Path: path.Join(rel, "Dockerfile"), Content: content}
		f.set("runtime", "docker", fmt.Sprintf("detected: %s (%s); starter Dockerfile written by falak init", lang, evidence))
		f.set("port", port, "EXPOSE "+fmt.Sprint(port)+" in the starter Dockerfile")
		f.sub("build", "").set("mode", "docker", "")
		u.Reasons = append(u.Reasons, lang, "starter Dockerfile")
	default:
		if rel != "." {
			d.Notes = append(d.Notes, fmt.Sprintf("%s: %s (%s) needs a Dockerfile; left out (`falak init --dockerfile` writes a starter one)", rel, lang, evidence))
			return nil, nil
		}
		d.Unsupported = &unsupportedLang{Language: lang, Evidence: evidence, Root: rel}
		return nil, nil
	}
	u.needs = detectNeeds(r, packageFile{}, composerFile{})
	u.envFile = readEnvExample(r)
	return u, nil
}

func relOrRoot(rel string) string {
	if rel == "." {
		return "the repository"
	}
	return rel
}

var goVersionRe = regexp.MustCompile(`(?m)^go\s+([0-9]+\.[0-9]+)`)

// starterDockerfile writes a first Dockerfile for a language Falak doesn't build natively. It is a starting point:
// the comments say what to check.
func starterDockerfile(lang string, r fsRepo) (string, bool) {
	var b strings.Builder
	w := func(lines ...string) {
		for _, l := range lines {
			b.WriteString(l + "\n")
		}
	}
	w("# Starter Dockerfile written by `falak init --dockerfile`: check the build and start commands.")
	switch lang {
	case "go":
		ver := "1.25"
		if m := goVersionRe.FindStringSubmatch(r.read("go.mod")); m != nil {
			ver = m[1]
		}
		pkg := "."
		if !r.exists("main.go") {
			if mains, _ := filepath.Glob(filepath.Join(r.dir, "cmd", "*", "main.go")); len(mains) > 0 {
				sort.Strings(mains)
				pkg = "./cmd/" + filepath.Base(filepath.Dir(mains[0]))
			}
		}
		w("FROM golang:"+ver+" AS build", "WORKDIR /src", "COPY go.mod go.sum* ./", "RUN go mod download", "COPY . .",
			"RUN CGO_ENABLED=0 go build -trimpath -ldflags=\"-s -w\" -o /out/app "+pkg, "",
			"FROM gcr.io/distroless/static-debian12:nonroot", "COPY --from=build /out/app /app",
			"ENV PORT=8080", "EXPOSE 8080", "USER nonroot", `ENTRYPOINT ["/app"]`)
	case "python":
		reqs := strings.ToLower(r.read("requirements.txt") + r.read("pyproject.toml"))
		install := "RUN pip install --no-cache-dir -r requirements.txt"
		copyDeps := "COPY requirements.txt ./"
		if !r.exists("requirements.txt") {
			copyDeps, install = "COPY . .", "RUN pip install --no-cache-dir ."
		}
		cmd := `CMD ["sh", "-c", "python main.py"]`
		server := ""
		switch {
		case r.exists("manage.py"):
			project := "app"
			if m, _ := filepath.Glob(filepath.Join(r.dir, "*", "wsgi.py")); len(m) > 0 {
				project = filepath.Base(filepath.Dir(m[0]))
			}
			if !strings.Contains(reqs, "gunicorn") {
				server = "gunicorn"
			}
			cmd = fmt.Sprintf(`CMD ["sh", "-c", "python manage.py migrate --noinput && gunicorn %s.wsgi --bind 0.0.0.0:$PORT"]`, project)
		case strings.Contains(reqs, "fastapi"):
			if !strings.Contains(reqs, "uvicorn") {
				server = "uvicorn"
			}
			cmd = fmt.Sprintf(`CMD ["sh", "-c", "uvicorn %s:app --host 0.0.0.0 --port $PORT"]`, pyModule(r))
		case strings.Contains(reqs, "flask"):
			if !strings.Contains(reqs, "gunicorn") {
				server = "gunicorn"
			}
			cmd = fmt.Sprintf(`CMD ["sh", "-c", "gunicorn %s:app --bind 0.0.0.0:$PORT"]`, pyModule(r))
		}
		w("FROM python:3.13-slim", "ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PORT=8000", "WORKDIR /app", copyDeps, install)
		if server != "" {
			w("RUN pip install --no-cache-dir " + server)
		}
		if copyDeps != "COPY . ." {
			w("COPY . .")
		}
		w("EXPOSE 8000", "USER nobody", cmd)
	case "ruby":
		w("FROM ruby:3.4-slim", "RUN apt-get update && apt-get install -y --no-install-recommends build-essential libpq-dev libyaml-dev && rm -rf /var/lib/apt/lists/*",
			"WORKDIR /app", "ENV RAILS_ENV=production BUNDLE_WITHOUT=development:test PORT=3000", "COPY Gemfile Gemfile.lock* ./",
			"RUN bundle install", "COPY . .")
		if r.exists("bin/rails") {
			w("RUN SECRET_KEY_BASE_DUMMY=1 bin/rails assets:precompile || true", "EXPOSE 3000",
				`CMD ["sh", "-c", "bin/rails db:prepare && bin/rails server -b 0.0.0.0 -p $PORT"]`)
		} else {
			w("EXPOSE 3000", `CMD ["sh", "-c", "bundle exec rackup -o 0.0.0.0 -p $PORT"]`)
		}
	case "rust":
		name := "app"
		if m := regexp.MustCompile(`(?m)^name\s*=\s*"([^"]+)"`).FindStringSubmatch(r.read("Cargo.toml")); m != nil {
			name = m[1]
		}
		w("FROM rust:1-slim AS build", "WORKDIR /src", "COPY . .", "RUN cargo build --release", "",
			"FROM debian:bookworm-slim", "RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*",
			"COPY --from=build /src/target/release/"+name+" /usr/local/bin/app", "ENV PORT=8080", "EXPOSE 8080", "USER nobody", `CMD ["app"]`)
	case "java", "kotlin":
		if r.exists("pom.xml") {
			w("FROM maven:3-eclipse-temurin-21 AS build", "WORKDIR /src", "COPY . .", "RUN mvn -q -DskipTests package", "",
				"FROM eclipse-temurin:21-jre", "COPY --from=build /src/target/*.jar /app/app.jar")
		} else {
			w("FROM gradle:8-jdk21 AS build", "WORKDIR /src", "COPY . .", "RUN gradle --no-daemon -q bootJar || gradle --no-daemon -q build -x test", "",
				"FROM eclipse-temurin:21-jre", "COPY --from=build /src/build/libs/*.jar /app/app.jar")
		}
		w("ENV PORT=8080 SERVER_PORT=8080", "EXPOSE 8080", "USER nobody", `CMD ["java", "-jar", "/app/app.jar"]`)
	default:
		return "", false
	}
	return b.String(), true
}

// pyModule is the module holding the WSGI/ASGI app object (main.py → main, app/main.py → app.main).
func pyModule(r fsRepo) string {
	for _, c := range []struct{ file, mod string }{{"main.py", "main"}, {"app.py", "app"}, {"app/main.py", "app.main"}, {"src/main.py", "src.main"}, {"wsgi.py", "wsgi"}} {
		if r.exists(c.file) {
			return c.mod
		}
	}
	return "main"
}

// writeStarter writes the starter Dockerfile, refusing to replace one.
func writeStarter(dir string, s *starterFile) error {
	p := filepath.Join(dir, filepath.FromSlash(s.Path))
	if _, err := os.Stat(p); err == nil {
		return fmt.Errorf("%s exists", s.Path)
	}
	return os.WriteFile(p, []byte(s.Content), 0o644)
}
