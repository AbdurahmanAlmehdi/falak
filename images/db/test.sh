#!/usr/bin/env bash
# Smoke-test a Falak database image against a real container.
#
#   images/db/test.sh <engine> <version>          # build (images/db/build.sh), then test
#   IMAGE=falak-postgres:17-ci images/db/test.sh postgres 17   # test an image that is already loaded
#   SKIP_PITR=1 images/db/test.sh mysql 8.4       # skip physical backup + point-in-time recovery
#   ONLY_STATS=1 images/db/test.sh redis 8         # start, healthcheck, `falak-db stats` and `observe` only
#
# Checks: start with *_FILE secrets and a TLS certificate, healthcheck, config tuned to the memory limit, TLS
# served, create a database, logical backup + restore, WAL archiving into the spool (postgres) or binlog rotation and
# spooling (mysql/mariadb), physical backup + restore into a new volume + recovery to a time between an insert and a
# DROP TABLE (postgres, mysql, mariadb), RDB backup + offline restore (redis/valkey), and the restore drill checks
# (table-counts, a read-only check query; the key count for redis/valkey).
set -euo pipefail

engine=${1:?engine (postgres|mysql|mariadb|redis|valkey)}
version=${2:?version}
here=$(cd "$(dirname "$0")" && pwd)

if [ -z "${IMAGE:-}" ]; then
	IMAGE=$("$here/build.sh" "$engine" "$version" --quiet | tail -n1)
fi

id="fdbt-$engine-${version//./}-$$"
work=$(mktemp -d)
containers=()
volumes=()
cleanup() {
	local rc=$?
	if [ "$rc" -ne 0 ]; then
		for c in "${containers[@]}"; do
			echo "--- logs of $c (last 40 lines)" >&2
			docker logs --tail 40 "$c" >&2 2>&1 || true
		done
	fi
	for c in "${containers[@]}"; do docker rm -f "$c" >/dev/null 2>&1 || true; done
	for v in "${volumes[@]}"; do docker volume rm -f "$v" >/dev/null 2>&1 || true; done
	rm -rf "$work"
	exit "$rc"
}
trap cleanup EXIT

step() { printf '\n== %s %s: %s\n' "$engine" "$version" "$*"; }
fail() {
	echo "FAIL: $*" >&2
	exit 1
}
expect() { # expect <what> <got> <want>
	[ "$2" = "$3" ] || fail "$1: got '$2', want '$3'"
	echo "ok: $1 = $3"
}

# Secrets and a self-signed certificate, readable by the engine's user inside the container.
mkdir -p "$work/secrets" "$work/tls"
openssl rand -hex 16 | tr -d '\n' >"$work/secrets/password"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$id" \
	-keyout "$work/tls/server.key" -out "$work/tls/server.crt" 2>/dev/null
cp "$work/tls/server.crt" "$work/tls/ca.crt"
chmod 0755 "$work/secrets" "$work/tls"
chmod 0644 "$work/secrets/password" "$work/tls/"*
password=$(cat "$work/secrets/password")

case "$engine" in
postgres)
	datadir=/var/lib/postgresql/data
	[ "$version" -ge 18 ] && datadir=/var/lib/postgresql/18/docker
	mount=$datadir
	[ "$version" -ge 18 ] && mount=/var/lib/postgresql
	secret_env=(-e POSTGRES_PASSWORD_FILE=/run/secrets/password)
	;;
mysql)
	datadir=/var/lib/mysql mount=/var/lib/mysql
	secret_env=(-e MYSQL_ROOT_PASSWORD_FILE=/run/secrets/password)
	;;
mariadb)
	datadir=/var/lib/mysql mount=/var/lib/mysql
	secret_env=(-e MARIADB_ROOT_PASSWORD_FILE=/run/secrets/password)
	;;
redis | valkey)
	datadir=/data mount=/data
	secret_env=(-e FALAK_DB_PASSWORD_FILE=/run/secrets/password)
	;;
*) fail "unknown engine $engine" ;;
esac

volume() {
	docker volume create "$1" >/dev/null
	volumes+=("$1")
}

# start <name> <data volume> [docker run args...]
start() {
	local name=$1 vol=$2
	shift 2
	containers+=("$name")
	docker run -d --name "$name" --memory 512m \
		-v "$vol:$mount" -v "$id-spool:/var/lib/falak/db/spool" \
		-v "$work/secrets:/run/secrets:ro" -v "$work/tls:/run/falak/db/tls:ro" \
		"${secret_env[@]}" "$@" "$IMAGE" >/dev/null
}

wait_healthy() {
	local name=$1 status=
	for _ in $(seq 1 120); do
		status=$(docker inspect -f '{{.State.Health.Status}}' "$name")
		[ "$status" = healthy ] && {
			echo "ok: $name healthy"
			return 0
		}
		[ "$(docker inspect -f '{{.State.Running}}' "$name")" = true ] || fail "$name exited"
		sleep 2
	done
	fail "$name not healthy after 240 s (status: $status)"
}

# helper <volume> <falak-db args...>: run falak-db in a throwaway container on a data volume (server stopped).
helper() {
	local vol=$1
	shift
	docker run --rm -i --entrypoint falak-db -v "$vol:$mount" -v "$id-spool:/var/lib/falak/db/spool" \
		-v "$work/secrets:/run/secrets:ro" "${secret_env[@]}" "$IMAGE" "$@"
}

result_of() { sed -n 's/^falak-db-result: //p' "$1" | tail -n1; }

sql() { # sql <container> <database> <statement>: one value per line
	local c=$1 db=$2 q=$3
	case "$engine" in
	postgres) docker exec "$c" psql -h /var/run/postgresql -U postgres -d "$db" -AtqX -v ON_ERROR_STOP=1 -c "$q" ;;
	mysql) docker exec -e MYSQL_PWD="$password" "$c" mysql -uroot -N -B -D "$db" -e "$q" ;;
	mariadb) docker exec -e MYSQL_PWD="$password" "$c" mariadb -uroot -N -B -D "$db" -e "$q" ;;
	esac
}

kv() { # kv <container> <args...>
	local c=$1
	shift
	docker exec -e REDISCLI_AUTH="$password" "$c" "$engine-cli" -s /run/falak-db/server.sock --no-auth-warning "$@"
}


volume "$id-data"
volume "$id-spool"
c="$id-1"

step "start ($IMAGE)"
# PostgreSQL starts with query insights (pg_stat_statements preloaded), as new instances do.
qi_env=()
[ "$engine" = postgres ] && qi_env=(-e FALAK_DB_QUERY_INSIGHTS=1)
start "$c" "$id-data" ${qi_env[@]+"${qi_env[@]}"}
wait_healthy "$c"
docker exec "$c" falak-db health | jq -e '.status == "healthy"' >/dev/null || fail "health JSON"
docker exec "$c" falak-db version | jq -e ".engine == \"$engine\"" >/dev/null || fail "version JSON"
if docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" | grep -q "$password"; then
	fail "the password is in the container's environment"
fi

step "stats (connections for the heartbeat)"
stats=$(docker exec "$c" falak-db stats) || fail "falak-db stats failed"
echo "$stats"
echo "$stats" | jq -e '(.connections | type == "number") and .connections >= 0 and .max_connections > 0 and .connections <= .max_connections' >/dev/null ||
	fail "stats JSON: $stats"
case "$engine" in
mysql | mariadb) echo "performance_schema: $(sql "$c" mysql 'SELECT @@performance_schema')" ;; # 0 below 1 GiB: stats must not need it
esac

step "observe (engine metrics, slow queries, query insights)"
obs=$(docker exec "$c" falak-db observe --detail) || fail "falak-db observe failed"
echo "$obs" | jq -c '{version, query_insights, gauges, warnings}'
echo "$obs" | jq -e '(.counters | length) > 0 and .gauges.max_connections > 0 and .gauges.connections >= 0 and .cursor.started == true and (.features | index("observe"))' >/dev/null ||
	fail "observe JSON: $obs"
cursor=$(echo "$obs" | jq -c .cursor)
case "$engine" in
postgres)
	echo "$obs" | jq -e '.query_insights.status == "on" and (.databases | length) > 0 and .settings.shared_buffers_bytes > 0' >/dev/null ||
		fail "postgres observe: $obs"
	expect "pg_stat_statements" "$(sql "$c" postgres "SELECT count(*) FROM pg_extension WHERE extname = 'pg_stat_statements'")" 1
	;;
mysql | mariadb)
	sql "$c" mysql "SELECT SLEEP(1.2), 'secret-literal'" >/dev/null
	obs=$(docker exec "$c" falak-db observe --cursor "$cursor") || fail "falak-db observe --cursor failed"
	echo "$obs" | jq -c '.slow'
	echo "$obs" | jq -e '[.slow[] | select(.query | startswith("SELECT SLEEP(?), ?"))] | length == 1' >/dev/null || fail "slow log entry: $obs"
	echo "$obs" | grep -q secret-literal && fail "a literal reached the observation"
	echo "$obs" | jq -e '.counters.slow_queries >= 1 and .counters.cache_hits >= 0' >/dev/null || fail "mysql counters: $obs"
	;;
redis | valkey)
	kv "$c" EVAL 'local i = 0 while i < 3000000 do i = i + 1 end return i' 0 >/dev/null
	obs=$(docker exec "$c" falak-db observe --cursor "$cursor") || fail "falak-db observe --cursor failed"
	echo "$obs" | jq -c '.slow'
	echo "$obs" | jq -e '[.slow[] | select(.query | startswith("EVAL"))] | length >= 1' >/dev/null || fail "SLOWLOG entry: $obs"
	echo "$obs" | grep -q '3000000' && fail "an argument reached the observation"
	echo "$obs" | jq -e '.gauges.used_memory > 0 and .gauges.maxmemory > 0 and .labels.maxmemory_policy == "noeviction"' >/dev/null || fail "kv gauges: $obs"
	;;
esac
case "$engine" in
postgres | mysql | mariadb)
	docker exec "$c" falak-db reload | jq -e '.reloaded == true' >/dev/null || fail "falak-db reload"
	;;
esac

if [ -n "${ONLY_STATS:-}" ]; then
	echo "ok: stats only"
	exit 0
fi

step "config render / tuning (512 MiB)"
render=$(docker exec "$c" falak-db config render --memory-bytes 536870912)
echo "$render" | jq -c .tuning
case "$engine" in
postgres)
	expect shared_buffers "$(sql "$c" postgres 'SHOW shared_buffers')" 128MB
	expect effective_cache_size "$(sql "$c" postgres 'SHOW effective_cache_size')" 384MB
	expect archive_mode "$(sql "$c" postgres 'SHOW archive_mode')" on
	expect wal_level "$(sql "$c" postgres 'SHOW wal_level')" replica
	expect ssl "$(sql "$c" postgres 'SHOW ssl')" on
	;;
mysql | mariadb)
	expect innodb_buffer_pool_size "$(sql "$c" mysql 'SELECT @@innodb_buffer_pool_size DIV 1048576')" 281
	expect log_bin "$(sql "$c" mysql 'SELECT @@log_bin')" 1
	expect binlog_format "$(sql "$c" mysql 'SELECT @@binlog_format')" ROW
	expect sync_binlog "$(sql "$c" mysql 'SELECT @@sync_binlog')" 1
	if [ "$engine" = mysql ]; then
		expect gtid_mode "$(sql "$c" mysql 'SELECT @@gtid_mode')" ON
		expect tls "$(docker exec -e MYSQL_PWD="$password" "$c" mysql -uroot -h127.0.0.1 --ssl-mode=REQUIRED -N -B -e "SHOW STATUS LIKE 'Ssl_version'" | awk '{print ($2 != "") ? "on" : "off"}')" on
	else
		expect gtid_strict_mode "$(sql "$c" mysql 'SELECT @@gtid_strict_mode')" 1
		expect tls "$(docker exec -e MYSQL_PWD="$password" "$c" mariadb -uroot -h127.0.0.1 --ssl --skip-ssl-verify-server-cert -N -B -e "SHOW STATUS LIKE 'Ssl_version'" | awk '{print ($2 != "") ? "on" : "off"}')" on
	fi
	;;
redis | valkey)
	expect maxmemory "$(kv "$c" INFO memory | tr -d '\r' | sed -n 's/^maxmemory://p')" 429496729
	# Plaintext TCP by default (the private network); TLS-only needs require_tls (see the redis-512m-require-tls golden).
	expect tcp "$(docker exec -e REDISCLI_AUTH="$password" "$c" "$engine-cli" -h 127.0.0.1 -p 6379 --no-auth-warning PING)" PONG
	if docker exec "$c" "$engine-cli" -s /run/falak-db/server.sock PING 2>/dev/null | grep -q PONG; then
		fail "PING without a password succeeded"
	fi
	;;
esac

if [ "$engine" = redis ] || [ "$engine" = valkey ]; then
	step "RDB backup + offline restore"
	kv "$c" SET falak:smoke before >/dev/null
	docker exec "$c" falak-db backup logical --out - >"$work/dump.rdb" 2>"$work/backup.err"
	result_of "$work/backup.err" | jq -e '.format == "rdb" and .bytes > 0' >/dev/null || fail "backup result: $(cat "$work/backup.err")"
	head -c 5 "$work/dump.rdb" | grep -q REDIS || fail "not an RDB file"
	kv "$c" SET falak:smoke after >/dev/null
	docker stop -t 30 "$c" >/dev/null
	helper "$id-data" restore logical --in - <"$work/dump.rdb" | jq -e '.restored' >/dev/null
	docker start "$c" >/dev/null
	wait_healthy "$c"
	expect "restored key" "$(kv "$c" GET falak:smoke)" before
	expect "key count (restore drills)" "$(docker exec "$c" falak-db table-counts | jq -r '.tables.db0')" 1
	echo "PASS $engine $version"
	exit 0
fi

step "create a database, logical backup + restore"
case "$engine" in
postgres)
	sql "$c" postgres 'CREATE DATABASE app' >/dev/null
	sql "$c" postgres 'CREATE DATABASE app_copy' >/dev/null
	sql "$c" app 'CREATE TABLE items (id int PRIMARY KEY, name text); INSERT INTO items SELECT g, md5(g::text) FROM generate_series(1, 1000) g' >/dev/null
	;;
*)
	sql "$c" mysql 'CREATE DATABASE app; CREATE DATABASE app_copy'
	sql "$c" app 'CREATE TABLE items (id int PRIMARY KEY, name text); INSERT INTO items WITH RECURSIVE g(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM g WHERE n < 1000) SELECT n, md5(n) FROM g'
	;;
esac
docker exec "$c" falak-db backup logical --database app --out - >"$work/app.dump" 2>"$work/backup.err"
result_of "$work/backup.err" | jq -e '.kind == "logical" and .database == "app" and .bytes > 0' >/dev/null ||
	fail "backup result: $(cat "$work/backup.err")"
[ "$(result_of "$work/backup.err" | jq -r .sha256)" = "$(shasum -a 256 "$work/app.dump" | cut -d' ' -f1)" ] || fail "sha256 of the stream"
docker exec -i "$c" falak-db restore logical --database app_copy --in - <"$work/app.dump" | jq -e .restored >/dev/null
expect "restored rows" "$(sql "$c" app_copy 'SELECT count(*) FROM items')" 1000

step "restore drill checks: row counts and a read-only check query"
items=$(docker exec "$c" falak-db table-counts --database app_copy | jq -r '.tables | to_entries[] | select(.key | endswith(".items")) | .value')
expect "row counts" "$items" 1000
expect "check query" "$(printf 'SELECT id FROM items WHERE id <= 10' | docker exec -i "$c" falak-db query --database app_copy | jq -r .rows)" 10
if printf 'SELECT 1; DROP TABLE items' | docker exec -i "$c" falak-db query --database app_copy >/dev/null 2>&1; then
	fail "a second statement was accepted"
fi
if [ "$engine" = postgres ] && printf 'SELECT * FROM items FOR UPDATE' | docker exec -i "$c" falak-db query --database app_copy >/dev/null 2>&1; then
	fail "FOR UPDATE ran: the check query is not read-only"
fi
expect "rows after the checks" "$(sql "$c" app_copy 'SELECT count(*) FROM items')" 1000

if [ "$engine" = postgres ]; then
	step "restore owned by the app user, swapped in: its migrations can alter what was restored"
	printf '%s' '{"username":"app_user","password":"app-user-pw-123","grants":[{"database":"app_copy","privileges":["ALL PRIVILEGES"]}],"state":"present"}' |
		docker exec -i "$c" sh -c 'cat >/tmp/user.json && falak-db user apply --spec /tmp/user.json && rm /tmp/user.json' | jq -e .changed >/dev/null
	sql "$c" app_copy 'CREATE SCHEMA billing; CREATE TABLE billing.invoices (id int)' >/dev/null
	docker exec -i "$c" falak-db restore logical --database app_copy --in - --swap --owner app_user <"$work/app.dump" | jq -e '.swapped and .owner == "app_user"' >/dev/null
	expect "rows after the swap" "$(sql "$c" app_copy 'SELECT count(*) FROM items')" 1000
	expect "table owner" "$(sql "$c" app_copy "SELECT tableowner FROM pg_tables WHERE tablename = 'items'")" app_user
	docker exec -e PGPASSWORD=app-user-pw-123 "$c" psql -h 127.0.0.1 -U app_user -d app_copy -v ON_ERROR_STOP=1 -c 'ALTER TABLE items ADD COLUMN migrated boolean' >/dev/null ||
		fail "ALTER TABLE as the app user after a restore"
	expect "the previous database is gone" "$(sql "$c" postgres "SELECT count(*) FROM pg_database WHERE datname LIKE 'app_copy__falak_%'")" 0
	echo "ok: restored objects belong to app_user"

	step "read-only mode and row counts"
	docker exec "$c" falak-db readonly on | jq -e '.read_only' >/dev/null
	if docker exec -e PGPASSWORD=app-user-pw-123 "$c" psql -h 127.0.0.1 -U app_user -d app_copy -v ON_ERROR_STOP=1 -c 'INSERT INTO items (id) VALUES (5000)' >/dev/null 2>&1; then
		fail "write accepted in read-only mode"
	fi
	docker exec "$c" falak-db readonly off | jq -e '.read_only == false' >/dev/null
	expect "row counts" "$(docker exec "$c" falak-db table-counts --database app_copy | jq -r '.tables["public.items"]')" 1000
fi

if [ "$engine" = postgres ]; then
	step "WAL archiving into the spool"
	seg=$(sql "$c" postgres 'SELECT pg_walfile_name(pg_switch_wal())')
	for _ in $(seq 1 30); do
		docker exec "$c" test -f "/var/lib/falak/db/spool/wal/$seg" && break
		sleep 1
	done
	docker exec "$c" test -f "/var/lib/falak/db/spool/wal/$seg" || fail "$seg not spooled"
	echo "ok: $seg spooled"
else
	step "binlog rotation into the spool"
	rot=$(docker exec "$c" falak-db binlog-rotate)
	echo "$rot" | jq -c '{current, spooled: [.spooled[].name]}'
	echo "$rot" | jq -e '.spooled | length > 0' >/dev/null || fail "nothing spooled"
	first=$(echo "$rot" | jq -r '.spooled[0].name')
	docker exec "$c" test -f "/var/lib/falak/db/spool/binlog/$first" || fail "$first not in the spool"
	again=$(docker exec "$c" falak-db binlog-rotate --no-flush)
	expect "spooled again without new binlogs" "$(echo "$again" | jq '.spooled | length')" 0
fi

if [ -n "${SKIP_PITR:-}" ]; then
	echo "PASS $engine $version (PITR skipped)"
	exit 0
fi

step "physical backup"
ext=tar
[ "$engine" = postgres ] || ext=xb
docker exec "$c" falak-db backup physical --out - >"$work/base.$ext" 2>"$work/base.err"
result_of "$work/base.err" | jq -c 'del(.sha256)'
result_of "$work/base.err" | jq -e '.kind == "physical" and .bytes > 0' >/dev/null || fail "backup result: $(tail -5 "$work/base.err")"

step "changes after the base: insert, then (later) DROP TABLE"
case "$engine" in
postgres)
	sql "$c" app 'CREATE TABLE pitr (v int); INSERT INTO pitr VALUES (42)' >/dev/null
	sleep 2
	target=$(sql "$c" postgres "SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")
	sleep 2
	sql "$c" app 'DROP TABLE pitr' >/dev/null
	seg=$(sql "$c" postgres 'SELECT pg_walfile_name(pg_switch_wal())')
	for _ in $(seq 1 30); do
		docker exec "$c" test -f "/var/lib/falak/db/spool/wal/$seg" && break
		sleep 1
	done
	;;
*)
	sql "$c" app 'CREATE TABLE pitr (v int); INSERT INTO pitr VALUES (42)'
	sleep 2
	target=$(sql "$c" mysql "SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ')")
	sleep 2
	sql "$c" app 'DROP TABLE pitr'
	docker exec "$c" falak-db binlog-rotate >/dev/null
	;;
esac
echo "target time: $target"

step "restore the base into a new volume, recover to the target"
volume "$id-pitr"
helper "$id-pitr" restore physical --in - <"$work/base.$ext" | jq -c .
c2="$id-2"
# The restored instance spools into its own spool; replay reads the original one, mounted at /replay.
volume "$id-spool2"
containers+=("$c2")
start2() {
	docker run -d --name "$c2" --memory 512m -v "$id-pitr:$mount" -v "$id-spool2:/var/lib/falak/db/spool" \
		-v "$id-spool:/replay" -v "$work/secrets:/run/secrets:ro" -v "$work/tls:/run/falak/db/tls:ro" \
		"${secret_env[@]}" "$IMAGE" >/dev/null
}
case "$engine" in
postgres)
	docker run --rm --entrypoint falak-db -v "$id-pitr:$mount" -v "$id-spool:/replay" "$IMAGE" \
		recover --target-time "$target" --wal-dir /replay/wal --action pause | jq -c .
	start2
	wait_healthy "$c2"
	for _ in $(seq 1 60); do
		[ "$(sql "$c2" postgres 'SELECT pg_get_wal_replay_pause_state()')" = paused ] && break
		sleep 1
	done
	expect "paused at the target" "$(sql "$c2" postgres 'SELECT pg_get_wal_replay_pause_state()')" paused
	# While paused, the DROP TABLE in flight at the target still holds its lock on pitr: read another table.
	expect "rows while paused" "$(sql "$c2" app 'SELECT count(*) FROM items')" 1000
	docker exec "$c2" falak-db promote | jq -e .promoted >/dev/null
	expect "row before the target" "$(sql "$c2" app 'SELECT v FROM pitr')" 42
	expect "in recovery after promote" "$(sql "$c2" postgres 'SELECT pg_is_in_recovery()')" f
	sql "$c2" app 'INSERT INTO pitr VALUES (43)' >/dev/null
	;;
*)
	start2
	wait_healthy "$c2"
	expect "rows in the base" "$(sql "$c2" app 'SELECT count(*) FROM items')" 1000
	docker exec "$c2" falak-db recover --binlog-dir /replay/binlog --target-time "$target" | jq -c '{mode, binlogs, start_position}'
	expect "row before the target" "$(sql "$c2" app 'SELECT v FROM pitr')" 42
	;;
esac

echo "PASS $engine $version"
