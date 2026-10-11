package cli

import (
	"fmt"
	"regexp"
	"strings"
)

// publicPrefixes mark variables bundled into client code: never secrets, whatever their name says.
var publicPrefixes = []string{"VITE_", "NEXT_PUBLIC_", "NUXT_PUBLIC_", "PUBLIC_", "REACT_APP_", "EXPO_PUBLIC_", "GATSBY_", "VUE_APP_", "MIX_"}

var (
	secretSuffixes = []string{"_KEY", "_SECRET", "_TOKEN", "_PASS", "_PWD", "_PASSPHRASE", "_CREDENTIALS", "_PRIVATE_KEY", "_SALT", "_DSN"}
	secretWords    = []string{"PASSWORD", "PASSWD", "SECRET", "PRIVATE_KEY", "ACCESS_KEY", "API_KEY", "CLIENT_SECRET", "AUTH_TOKEN"}
	notSecretWords = []string{"PUBLIC_KEY", "PUBLISHABLE_KEY", "KEY_PREFIX", "_KEY_FILE", "_KEYSPACE"}
	envKeyRe       = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,127}$`)
	secretNameRe   = regexp.MustCompile(`^[A-Z_][A-Z0-9_]*$`)
)

// isSecretKey says whether a variable holds a secret, by its name. APP_KEY (Laravel's encryption key) is one;
// VITE_PUSHER_APP_KEY is bundled into the browser and is not.
func isSecretKey(key string) bool {
	k := strings.ToUpper(key)
	for _, p := range publicPrefixes {
		if strings.HasPrefix(k, p) {
			return false
		}
	}
	for _, w := range notSecretWords {
		if strings.Contains(k, w) {
			return false
		}
	}
	if k == "SECRET" || k == "PASSWORD" || k == "TOKEN" {
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
	return false
}

// Keys whose values come from a database or redis unit. Laravel names first, then the generic URLs.
var (
	dbRefKeys = map[string]string{
		"DATABASE_URL": "DATABASE_URL", "DB_URL": "DATABASE_URL", "DB_CONNECTION": "DB_CONNECTION", "DB_HOST": "DB_HOST",
		"DB_PORT": "DB_PORT", "DB_DATABASE": "DB_DATABASE", "DB_USERNAME": "DB_USERNAME", "DB_PASSWORD": "DB_PASSWORD",
		"POSTGRES_URL": "DATABASE_URL", "MYSQL_URL": "DATABASE_URL",
	}
	redisRefKeys = map[string]string{
		"REDIS_URL": "REDIS_URL", "REDIS_HOST": "REDIS_HOST", "REDIS_PORT": "REDIS_PORT", "REDIS_PASSWORD": "REDIS_PASSWORD",
	}
	// productionValues replace development values of .env.example.
	productionValues = map[string]string{"APP_ENV": "production", "APP_DEBUG": "false", "NODE_ENV": "production", "RAILS_ENV": "production", "FLASK_ENV": "production", "DEBUG": "false"}
	// skippedKeys are set by Falak (APP_URL from the primary domain, PORT for the runtime).
	skippedKeys = map[string]bool{"APP_URL": true, "PORT": true}
)

func ref(unit, key string) string { return "${{ " + unit + "." + key + " }}" }

// buildEnv turns .env.example into the unit's env: secrets become ${{ secrets.NAME }}, database and Redis settings
// references to their units, development values production ones.
func buildEnv(u *detectedUnit, db, redis *detectedUnit, d *detection) {
	env := newOmap()
	var empty, secrets []string
	laravel := u.plan != nil && u.plan.Framework == "laravel"
	usesDB := db != nil && u.needs.db != ""
	usesRedis := redis != nil && u.needs.redis != ""
	for _, e := range u.envFile {
		k, v := e.key, e.value
		switch {
		case !envKeyRe.MatchString(k) || skippedKeys[k]:
			continue
		case laravel && k == "LOG_CHANNEL":
			continue // Falak's Laravel default (daily files) is what its log tailing reads
		case dbRefKeys[k] != "" && usesDB:
			env.set(k, ref(db.Key, dbRefKeys[k]), "")
		case dbRefKeys[k] != "" && !(k == "DB_CONNECTION" && v == "sqlite"):
			continue // no database unit: these point at a local database
		case k == "DB_CONNECTION":
			env.set(k, v, "SQLite: keep its file on a volume")
		case strings.HasPrefix(k, "REDIS_") && usesRedis:
			if rk := redisRefKeys[k]; rk != "" {
				env.set(k, ref(redis.Key, rk), "")
			} else if v != "" && !strings.Contains(v, "${{") {
				env.set(k, v, "")
			}
		case strings.HasPrefix(k, "REDIS_"):
			continue
		case isSecretKey(k):
			name := strings.ToUpper(k)
			if !secretNameRe.MatchString(name) {
				continue
			}
			env.set(k, ref("secrets", name), "")
			secrets = append(secrets, name)
		case productionValues[k] != "":
			env.set(k, productionValues[k], "")
		case v == "":
			empty = append(empty, k)
		case strings.Contains(v, "${{"):
			d.Notes = append(d.Notes, fmt.Sprintf("%s: left out %s (its example value looks like a reference)", u.Key, k))
		default:
			env.set(k, v, "")
		}
	}
	if usesDB && !hasAny(env, "DATABASE_URL", "DB_URL", "DB_HOST", "POSTGRES_URL", "MYSQL_URL") {
		env.set("DATABASE_URL", ref(db.Key, "DATABASE_URL"), "detected: "+u.needs.dbWhy)
	}
	if usesRedis && !hasAny(env, "REDIS_URL", "REDIS_HOST") {
		env.set("REDIS_URL", ref(redis.Key, "REDIS_URL"), "detected: "+u.needs.redisWhy)
	}
	if len(secrets) > 0 {
		d.Notes = append(d.Notes, fmt.Sprintf("%s: create these secrets before applying (falak validate checks them when logged in): %s", u.Key, strings.Join(secrets, ", ")))
	}
	if len(empty) > 0 {
		d.Notes = append(d.Notes, fmt.Sprintf("%s: empty in .env.example, left out: %s", u.Key, strings.Join(empty, ", ")))
	}
	if env.empty() {
		return
	}
	why := ""
	if len(u.envFile) > 0 {
		why = "from .env.example; secrets are ${{ secrets.NAME }} references"
	}
	u.fields.set("env", env, why)
}

func hasAny(m *omap, keys ...string) bool {
	for _, k := range keys {
		if m.has(k) {
			return true
		}
	}
	return false
}
