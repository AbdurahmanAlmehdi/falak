<?php

namespace Falak\Databases\Domain\Enums;

use LogicException;

/**
 * Database engines. Every instance is a container of the engine's Falak image (docs/DB_IMAGES.md); the agent protocol
 * names them postgres | mysql | mariadb | redis | valkey. Redis and Valkey are key-value engines: an instance has one
 * keyspace (one Database row) and a single `default` user, see {@see EngineKind}.
 */
enum Engine: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';
    case Redis = 'redis';
    case Valkey = 'valkey';

    public function label(): string
    {
        return match ($this) {
            self::MySql => 'MySQL',
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
            self::Redis => 'Redis',
            self::Valkey => 'Valkey',
        };
    }

    public function kind(): EngineKind
    {
        return match ($this) {
            self::Redis, self::Valkey => EngineKind::KeyValue,
            default => EngineKind::Sql,
        };
    }

    public function isKeyValue(): bool
    {
        return $this->kind() === EngineKind::KeyValue;
    }

    /** The `engine` value of db.* agent commands. */
    public function protocol(): string
    {
        return $this === self::PostgreSql ? 'postgres' : $this->value;
    }

    public function isMysqlFamily(): bool
    {
        return $this === self::MySql || $this === self::MariaDb;
    }

    /**
     * Supported majors, the default first (config databases.versions).
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return array_values(array_map('strval', (array) config("databases.versions.{$this->value}", [])));
    }

    public function defaultVersion(): string
    {
        return $this->versions()[0] ?? throw new LogicException("No {$this->label()} version is configured.");
    }

    /** The image tag of a major, e.g. ghcr.io/falak-sh/falak-postgres:17. */
    public function image(string $version): string
    {
        return config('databases.registry').'/falak-'.$this->protocol().':'.$version.config('databases.tag_suffix');
    }

    /** A pinned digest of the major's image (FALAK_DB_IMAGE_DIGEST_<ENGINE>_<MAJOR>, dots as underscores), or null. */
    public function pinnedDigest(string $version): ?string
    {
        $digest = ((array) config('databases.digests', []))[$this->value][$version] ?? null;

        return is_string($digest) && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1 ? $digest : null;
    }

    /** Memory limit of a new instance (bytes). */
    public function defaultMemory(): int
    {
        return (int) config("databases.memory.default.{$this->value}", 512 * 1024 ** 2);
    }

    public function minMemory(): int
    {
        return (int) config("databases.memory.min.{$this->value}", 64 * 1024 ** 2);
    }

    /** Data volume size of a new instance (bytes). */
    public function defaultDisk(): int
    {
        return (int) config("databases.disk.default.{$this->value}", 10 * 1024 ** 3);
    }

    /** The port the engine listens on inside its container. */
    public function defaultPort(): int
    {
        return match ($this) {
            self::PostgreSql => 5432,
            self::MySql, self::MariaDb => 3306,
            self::Redis, self::Valkey => 6379,
        };
    }

    /** URL scheme / Laravel driver name. */
    public function driver(): string
    {
        return match ($this) {
            self::MySql => 'mysql',
            self::MariaDb => 'mariadb',
            self::PostgreSql => 'pgsql',
            self::Redis, self::Valkey => 'redis',
        };
    }

    public function defaultCharset(): ?string
    {
        return $this->isMysqlFamily() ? 'utf8mb4' : null;
    }

    public function defaultCollation(): ?string
    {
        return match ($this) {
            self::MySql => 'utf8mb4_0900_ai_ci',
            self::MariaDb => 'utf8mb4_unicode_ci',
            default => null,
        };
    }

    /**
     * Names that must never be created, dropped or granted.
     *
     * @return list<string>
     */
    public function reservedNames(): array
    {
        return match ($this) {
            self::MySql, self::MariaDb => ['mysql', 'information_schema', 'performance_schema', 'sys', 'root', 'debian-sys-maint', 'mariadb.sys', 'mysql.sys', 'mysql.session', 'mysql.infoschema'],
            self::PostgreSql => ['postgres', 'template0', 'template1', 'pg_signal_backend', 'pg_monitor'],
            self::Redis, self::Valkey => ['default', 'falak'],
        };
    }

    /**
     * Privileges offered in the UI (db.user.apply accepts `^[A-Z ]+$`).
     *
     * @return list<string>
     */
    public function privileges(): array
    {
        if ($this->isKeyValue()) {
            return [];
        }

        return $this->isMysqlFamily()
            ? ['ALL PRIVILEGES', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES', 'CREATE TEMPORARY TABLES', 'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER']
            : ['ALL PRIVILEGES', 'CONNECT', 'CREATE', 'TEMPORARY'];
    }

    /**
     * The engine of a compose service's image (`postgres:16-alpine`, `bitnami/mysql`, `valkey/valkey:8`), or null.
     */
    public static function fromImage(string $image): ?self
    {
        $name = strtolower((string) preg_replace('/[:@].*$/', '', basename(str_replace('\\', '/', $image))));

        return match (true) {
            in_array($name, ['postgres', 'postgresql', 'postgis'], true) => self::PostgreSql,
            $name === 'mysql' => self::MySql,
            $name === 'mariadb' => self::MariaDb,
            $name === 'redis' || $name === 'redis-stack-server' => self::Redis,
            $name === 'valkey' => self::Valkey,
            default => null,
        };
    }

    /** The supported major closest to a compose image tag ("16-alpine" → 16), else the default. */
    public function versionFromTag(?string $tag): string
    {
        $tag = (string) $tag;

        foreach ($this->versions() as $version) {
            if ($tag === $version || str_starts_with($tag, $version.'.') || str_starts_with($tag, $version.'-')) {
                return $version;
            }
        }

        return $this->defaultVersion();
    }
}
