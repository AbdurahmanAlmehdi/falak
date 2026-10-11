package cli

import (
	"bufio"
	"fmt"
	"math"
	"regexp"
	"sort"
	"strings"
)

// ---- reading .env.example --------------------------------------------------------------------------------------

// envExampleNames are the example env files read, first found wins.
var envExampleNames = []string{".env.example", "env.example", ".env.sample", ".env.dist", "example.env"}

var dotenvLineRe = regexp.MustCompile(`^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$`)

type envEntry struct {
	key, value string
}

func readEnvExample(r fsRepo) []envEntry {
	content := ""
	for _, n := range envExampleNames {
		if content = r.read(n); content != "" {
			break
		}
	}
	var out []envEntry
	seen := map[string]bool{}
	sc := bufio.NewScanner(strings.NewReader(content))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		m := dotenvLineRe.FindStringSubmatch(line)
		if m == nil || seen[m[1]] {
			continue
		}
		seen[m[1]] = true
		out = append(out, envEntry{key: m[1], value: unquoteEnv(m[2])})
	}
	return out
}

// unquoteEnv reads a dotenv value: "double quotes" take \n, \t, \", \\ and \$ escapes; 'single quotes' are literal
// but for ” and \' (a quote); an unquoted value ends at " #".
func unquoteEnv(v string) string {
	v = strings.TrimSpace(v)
	if v == "" {
		return ""
	}
	switch v[0] {
	case '"':
		var b strings.Builder
		for i := 1; i < len(v); i++ {
			c := v[i]
			if c == '"' {
				return b.String()
			}
			if c == '\\' && i+1 < len(v) {
				i++
				switch v[i] {
				case 'n':
					b.WriteByte('\n')
				case 't':
					b.WriteByte('\t')
				case 'r':
					b.WriteByte('\r')
				case '"', '\\', '$':
					b.WriteByte(v[i])
				default:
					b.WriteByte('\\')
					b.WriteByte(v[i])
				}
				continue
			}
			b.WriteByte(c)
		}
		return b.String() // unterminated: the rest of the line
	case '\'':
		var b strings.Builder
		for i := 1; i < len(v); i++ {
			c := v[i]
			if c == '\\' && i+1 < len(v) && v[i+1] == '\'' {
				b.WriteByte('\'')
				i++
				continue
			}
			if c == '\'' {
				if i+1 < len(v) && v[i+1] == '\'' {
					b.WriteByte('\'')
					i++
					continue
				}
				return b.String()
			}
			b.WriteByte(c)
		}
		return b.String()
	}
	if i := strings.Index(v, " #"); i >= 0 {
		v = strings.TrimSpace(v[:i])
	}
	return v
}

func envMap(entries []envEntry) map[string]string {
	m := map[string]string{}
	for _, e := range entries {
		m[e.key] = e.value
	}
	return m
}

// ---- secrets ---------------------------------------------------------------------------------------------------

// publicPrefixes mark variables bundled into client code: their names never make them secret.
var publicPrefixes = []string{"VITE_", "NEXT_PUBLIC_", "NUXT_PUBLIC_", "PUBLIC_", "REACT_APP_", "EXPO_PUBLIC_", "GATSBY_", "VUE_APP_", "MIX_"}

var (
	// literalSuffixes name settings about a secret, not the secret (JWT_PRIVATE_KEY_PATH, AUTH_DRIVER).
	literalSuffixes = []string{"_PATH", "_FILE", "_DIR", "_ENABLED", "_DRIVER", "_MODE", "_TYPE", "_TTL", "_TIMEOUT", "_HOST",
		"_PORT", "_ALGORITHM", "_ALGO", "_ALG", "_EXPIRES", "_EXPIRES_IN", "_EXPIRY", "_LIFETIME", "_PREFIX", "_REGION", "_LENGTH"}
	secretSuffixes = []string{"_KEY", "_SECRET", "_TOKEN", "_PASS", "_PWD", "_PASSPHRASE", "_CREDENTIALS", "_SALT", "_DSN", "_SK", "_PIN", "_APIKEY"}
	secretWords    = []string{"PASSWORD", "PASSWD", "SECRET", "PRIVATE_KEY", "ACCESS_KEY", "API_KEY", "APIKEY", "AUTH_TOKEN", "WEBHOOK", "CREDENTIAL", "DSN"}
	notSecretWords = []string{"PUBLIC_KEY", "PUBLISHABLE_KEY", "KEY_PREFIX", "KEYSPACE"}
	// secretTokens are words of the name (split on _) that make it secret: BASIC_AUTH, SIGNING_KEY, …
	secretTokens = map[string]bool{"AUTH": true, "BASICAUTH": true, "SIGN": true, "SIGNING": true, "SIGNATURE": true, "WEBHOOK": true, "DSN": true, "CREDENTIALS": true, "PIN": true}
	// tokenExempt: AUTH_URL, SIGN_IN_URL, GOOGLE_AUTH_CLIENT_ID are not secrets.
	tokenExempt  = map[string]bool{"URL": true, "URI": true, "ID": true, "ISSUER": true, "AUDIENCE": true, "PROVIDER": true, "SCOPE": true, "SCOPES": true, "CALLBACK": true, "REDIRECT": true}
	secretNameRe = regexp.MustCompile(`^[A-Z_][A-Z0-9_]*$`)
)

// isSecretKey says whether a variable holds a secret, by its name. APP_KEY (Laravel's encryption key) is one;
// VITE_PUSHER_APP_KEY is bundled into the browser and is not; JWT_PRIVATE_KEY_PATH is a path.
func isSecretKey(key string) bool {
	k := strings.ToUpper(key)
	for _, p := range publicPrefixes {
		if strings.HasPrefix(k, p) {
			return false
		}
	}
	for _, s := range literalSuffixes {
		if strings.HasSuffix(k, s) {
			return false
		}
	}
	for _, w := range notSecretWords {
		if strings.Contains(k, w) {
			return false
		}
	}
	switch k {
	case "SECRET", "PASSWORD", "TOKEN", "APIKEY", "DSN", "PIN", "KEY":
		return true
	}
	for _, s := range secretSuffixes {
		if strings.HasSuffix(k, s) {
			return true
		}
	}
	for _, w := range secretWords {
		if strings.Contains(k, w) {
			return true
		}
	}
	tokens := strings.Split(k, "_")
	for _, t := range tokens {
		if secretTokens[t] && !tokenExempt[tokens[len(tokens)-1]] {
			return true
		}
	}
	return false
}

var (
	userinfoRe     = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9+.\-]*://([^/?#@\s]*)@`)
	webhookRe      = regexp.MustCompile(`(?i)^https?://(hooks\.slack\.com/(services|workflows|triggers)/|(\w+\.)?discord(app)?\.com/api/webhooks/|[\w.-]*webhook\.office\.com/|outlook\.office(365)?\.com/webhook|api\.telegram\.org/bot|hooks\.zapier\.com/hooks/|chat\.googleapis\.com/.*key=|maker\.ifttt\.com/trigger/)`)
	tokenPrefixRe  = regexp.MustCompile(`^(base64:|sk_(live|test)_|sk-|rk_(live|test)_|pk_live_|ghp_|gho_|ghu_|ghs_|ghr_|github_pat_|xox[abposr]-|glpat-|whsec_|shpat_|shpss_|npm_[A-Za-z0-9]{20}|AKIA[0-9A-Z]{16}|SG\.[A-Za-z0-9_-]{16,}|AIza[0-9A-Za-z_-]{20,}|eyJ[A-Za-z0-9_-]{10,}\.)`)
	hexTokenRe     = regexp.MustCompile(`^[0-9a-fA-F]{24,}$`)
	opaqueTokenRe  = regexp.MustCompile(`^[A-Za-z0-9+/_\-.]{24,}={0,2}$`)
	letterDigitsRe = regexp.MustCompile(`[A-Za-z].*[0-9]|[0-9].*[A-Za-z]`)
)

// isSecretValue says whether a value looks like a credential, whatever its name: a URL with a password (or a
// token as its user), a webhook URL, a known token format, or a long random string.
func isSecretValue(v string) bool {
	if v == "" || strings.Contains(v, "${") {
		return false
	}
	if m := userinfoRe.FindStringSubmatch(v); m != nil {
		user, pass, hasPass := strings.Cut(m[1], ":")
		if hasPass && pass != "" {
			return true
		}
		if len(user) >= 16 && entropy(user) >= 3.0 {
			return true // https://<key>@sentry.io/1
		}
	}
	if webhookRe.MatchString(v) || tokenPrefixRe.MatchString(v) {
		return true
	}
	if hexTokenRe.MatchString(v) {
		return true
	}
	if opaqueTokenRe.MatchString(v) && letterDigitsRe.MatchString(v) {
		limit := 3.5
		if strings.ContainsAny(v, "/.") {
			limit = 4.2 // paths and host names need to look much more random
		}
		return entropy(v) >= limit
	}
	return false
}

// entropy is the Shannon entropy of s in bits per character.
func entropy(s string) float64 {
	counts := map[rune]float64{}
	n := 0.0
	for _, c := range s {
		counts[c]++
		n++
	}
	h := 0.0
	for _, c := range counts {
		p := c / n
		h -= p * math.Log2(p)
	}
	return h
}

// ---- building a unit's env -------------------------------------------------------------------------------------

var (
	// Keys whose value comes from a database unit (Laravel names, then any *_DATABASE_URL-style URL key).
	dbRefKeys = map[string]string{
		"DB_CONNECTION": "DB_CONNECTION", "DB_HOST": "DB_HOST", "DB_PORT": "DB_PORT", "DB_DATABASE": "DB_DATABASE",
		"DB_USERNAME": "DB_USERNAME", "DB_PASSWORD": "DB_PASSWORD",
	}
	dbURLKeyRe    = regexp.MustCompile(`^(.*_)?(DATABASE_URL|DB_URL|DB_DSN|POSTGRES_URL|POSTGRESQL_URL|PG_URL|MYSQL_URL|MARIADB_URL)$`)
	redisRefKeys  = map[string]string{"REDIS_HOST": "REDIS_HOST", "REDIS_PORT": "REDIS_PORT", "REDIS_PASSWORD": "REDIS_PASSWORD"}
	redisURLKeyRe = regexp.MustCompile(`^(.*_)?(REDIS_URL|REDIS_DSN|VALKEY_URL)$`)
	redisAddrRe   = regexp.MustCompile(`^(.*_)?REDIS_ADDR(ESS)?$`)
	// productionValues replace development values of .env.example.
	productionValues = map[string]string{"APP_ENV": "production", "APP_DEBUG": "false", "NODE_ENV": "production", "RAILS_ENV": "production",
		"RACK_ENV": "production", "FLASK_ENV": "production", "FLASK_DEBUG": "0", "DEBUG": "false", "GIN_MODE": "release", "MIX_ENV": "prod"}
	// skippedKeys are set by Falak (APP_URL from the primary domain, PORT for the runtime).
	skippedKeys = map[string]bool{"APP_URL": true, "PORT": true}
	loopbackRe  = regexp.MustCompile(`^(localhost|127\.0\.0\.1|0\.0\.0\.0|\[?::1\]?|host\.docker\.internal)(:([0-9]+))?$`)
	urlHostRe   = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9+.\-]*://(?:[^/?#@]*@)?(localhost|127\.0\.0\.1|\[::1\]|host\.docker\.internal)([:/?#]|$)`)
	interpRe    = regexp.MustCompile(`\$\{([A-Za-z_][A-Za-z0-9_]*)\}`)
)

// isBindKey: the address the app itself listens on (HTTP_ADDR, LISTEN_ADDR, BIND, HOST), not a service it calls.
func isBindKey(k string) bool {
	k = strings.ToUpper(k)
	for _, p := range []string{"REDIS", "DB_", "DATABASE", "MAIL", "SMTP", "MEMCACHED", "POSTGRES", "MYSQL", "MONGO", "RABBIT", "AMQP", "ELASTIC", "MEILI", "TYPESENSE", "MINIO", "S3", "OTEL", "STATSD", "SENTRY"} {
		if strings.Contains(k, p) {
			return false
		}
	}
	switch k {
	case "HOST", "APP_HOST", "SERVER_HOST", "HTTP_HOST", "BIND_HOST", "LISTEN_HOST", "ADDR", "ADDRESS", "BIND", "LISTEN":
		return true
	}
	return strings.Contains(k, "LISTEN") || strings.Contains(k, "BIND") || strings.HasSuffix(k, "_ADDR") || strings.HasSuffix(k, "_ADDRESS") && strings.Contains(k, "HTTP")
}

func ref(unit, key string) string { return "${{ " + unit + "." + key + " }}" }

// envValue is the value a key gets, with its comment.
type envValue struct {
	value, comment string
	literal        bool // from the example file (interpolation applies)
}

// buildEnv turns .env.example into the unit's env. Secrets (by name or by value) become ${{ secrets.NAME }},
// database and Redis settings references to their units, development values production ones; values pointing at
// the local machine are left out. No secret value is ever written, in the file or in the notes.
func buildEnv(u *detectedUnit, db, redis *detectedUnit, d *detection) {
	laravel := u.plan != nil && u.plan.Framework == "laravel"
	usesDB := db != nil && u.needs.db != ""
	usesRedis := redis != nil && u.needs.redis != ""
	vals := map[string]*envValue{}
	var order, empty, secrets, local, valueSecrets []string
	wiredDB, wiredRedis := false, false
	set := func(k, v, comment string, literal bool) {
		vals[k] = &envValue{value: v, comment: comment, literal: literal}
		order = append(order, k)
	}
	for _, e := range u.envFile {
		k, v := e.key, e.value
		up := strings.ToUpper(k)
		switch {
		case !envKeyRe.MatchString(k) || skippedKeys[up]:
		case laravel && up == "LOG_CHANNEL" && u.Type == "app" && u.Runtime != "docker":
			// Falak's Laravel default (daily files) is what its log tailing reads.
		case dbRefKeys[up] != "" && usesDB:
			set(k, ref(db.Key, dbRefKeys[up]), "", false)
			wiredDB = true
		case dbURLKeyRe.MatchString(up) && usesDB:
			set(k, ref(db.Key, "DATABASE_URL"), "", false)
			wiredDB = true
		case up == "DB_CONNECTION" && v == "sqlite":
			set(k, v, "", true)
			d.Notes = append(d.Notes, fmt.Sprintf("%s: SQLite keeps its data in a file that a new release doesn't keep; use a database unit (type: database) instead", u.Key))
		case dbRefKeys[up] != "" || dbURLKeyRe.MatchString(up):
			// No database unit: these point at a local database.
		case usesRedis && redisURLKeyRe.MatchString(up):
			set(k, ref(redis.Key, "REDIS_URL"), "", false)
			wiredRedis = true
		case usesRedis && redisRefKeys[up] != "":
			set(k, ref(redis.Key, redisRefKeys[up]), "", false)
			wiredRedis = true
		case usesRedis && redisAddrRe.MatchString(up):
			set(k, ref(redis.Key, "REDIS_HOST")+":"+ref(redis.Key, "REDIS_PORT"), "", false)
			wiredRedis = true
		case strings.HasPrefix(up, "REDIS_") && !usesRedis:
		case isSecretKey(k) || isSecretValue(v):
			name := strings.ToUpper(k)
			if !secretNameRe.MatchString(name) {
				continue
			}
			set(k, ref("secrets", name), "", false)
			secrets = append(secrets, name)
			if !isSecretKey(k) {
				valueSecrets = append(valueSecrets, k)
			}
		case productionValues[up] != "":
			set(k, productionValues[up], "", false)
		case (up == "LOG_LEVEL" || strings.HasSuffix(up, "_LOG_LEVEL")) && (strings.EqualFold(v, "debug") || strings.EqualFold(v, "trace")):
			local = append(local, k)
		case v == "" || v == "null":
			empty = append(empty, k)
		case strings.Contains(v, "${{"):
			d.Notes = append(d.Notes, fmt.Sprintf("%s: left out %s (its example value looks like a reference)", u.Key, k))
		case loopbackRe.MatchString(v) && isBindKey(k):
			m := loopbackRe.FindStringSubmatch(v)
			bind := "0.0.0.0"
			if m[3] != "" {
				bind += ":" + m[3]
			}
			set(k, bind, "was "+v+" in the example: an app in a container listens on all interfaces", false)
		case loopbackRe.MatchString(v) || urlHostRe.MatchString(v):
			local = append(local, k)
		default:
			set(k, v, "", true)
		}
	}

	// ${VAR} interpolation (dotenv): expanded with the other keys' values here, references included.
	var expanded []string
	for _, k := range order {
		ev := vals[k]
		if !ev.literal || !interpRe.MatchString(ev.value) {
			continue
		}
		missing := false
		out := interpRe.ReplaceAllStringFunc(ev.value, func(m string) string {
			name := interpRe.FindStringSubmatch(m)[1]
			if other, ok := vals[name]; ok && other != ev && !interpRe.MatchString(other.value) {
				return other.value
			}
			missing = true
			return m
		})
		if missing {
			delete(vals, k)
			d.Notes = append(d.Notes, fmt.Sprintf("%s: left out %s (it interpolates a variable this file doesn't set)", u.Key, k))
			continue
		}
		ev.value = out
		expanded = append(expanded, k)
	}

	env := newOmap()
	for _, k := range order {
		if ev, ok := vals[k]; ok {
			env.set(k, ev.value, ev.comment)
		}
	}
	if usesDB && !wiredDB {
		env.set("DATABASE_URL", ref(db.Key, "DATABASE_URL"), "detected: "+u.needs.dbWhy)
	}
	if usesRedis && !wiredRedis {
		env.set("REDIS_URL", ref(redis.Key, "REDIS_URL"), "detected: "+u.needs.redisWhy)
	}
	if len(valueSecrets) > 0 {
		d.Notes = append(d.Notes, fmt.Sprintf("%s: the example values of %s look like credentials: they became secret references", u.Key, strings.Join(valueSecrets, ", ")))
	}
	if len(secrets) > 0 {
		d.Notes = append(d.Notes, fmt.Sprintf("%s: create these secrets before applying (falak validate checks them when logged in): %s", u.Key, strings.Join(secrets, ", ")))
	}
	if len(expanded) > 0 {
		d.Notes = append(d.Notes, fmt.Sprintf("%s: expanded ${VAR} in %s", u.Key, strings.Join(expanded, ", ")))
	}
	if len(local) > 0 {
		sort.Strings(local)
		d.Notes = append(d.Notes, fmt.Sprintf("%s: left out local development values: %s", u.Key, strings.Join(local, ", ")))
	}
	if len(empty) > 0 {
		d.Notes = append(d.Notes, fmt.Sprintf("%s: empty in the example env file, left out: %s", u.Key, strings.Join(empty, ", ")))
	}
	if env.empty() {
		return
	}
	why := ""
	if len(u.envFile) > 0 {
		why = "from the example env file; secrets are ${{ secrets.NAME }} references"
	}
	u.fields.set("env", env, why)
}

var envKeyRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,127}$`)
