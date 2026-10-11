# falak.yaml contract (v1)

The schema of `falak.yaml` / `falak.json` and the cases both implementations run. The rules behind them are in
[docs/plans/CONFIG_AS_CODE.md](../../docs/plans/CONFIG_AS_CODE.md), section "Phase 0 contract (fixed)".

| File | What |
|---|---|
| `v1.schema.json` | JSON Schema (draft 2020-12), `$id` `https://falak.sh/schema/v1.json`. Every object is closed (`additionalProperties: false`); descriptions are the editor tooltips. |
| `risky-changes.json` | The rules that make a plan wait for an approval, as data. |
| `cases/valid/` | Files that must parse and pass the schema. |
| `cases/invalid/` | Files that must fail, each with `<name>.expect.json`. |
| `cases/parse/` | Files with the exact JSON value both parsers must read (`<name>.expect.json`). |
| `cases/resolve/` | A file and, per environment, the merged ("resolved") file and the issues the merge and reference checks report. |

## Cases

**invalid**: `{"code": "...", "pointer": "..."}`.

- `code: "schema"`: the schema rejects the file, and one of the failing leaves of the validation is at `pointer`. For
  unknown, missing or badly named properties that is the object holding them (e.g. `/units` for a unit key in upper
  case), else the value itself (`/units/web/deploy/strategy`).
- `code: "parse..."`: reading the file fails with that code (`parse`, `parse.empty`, `parse.timestamp`,
  `parse.number`, `parse.tag`, `parse.duplicate_key`, `parse.multiple_documents`). `pointer` is where, or `null` when
  the YAML parser reports the error itself (symfony/yaml gives no pointer for those; yaml.v3 may).

**parse**: the expect file is the parsed document. It pins where YAML 1.1 parsers differ (`yes`, `0755`, `08`, `-0`,
`0b11`, dates, merge keys): both sides must read exactly this.

**resolve**:

```json
{"environments": {"staging": {"resolved": {...} | null, "errors": [Issue], "warnings": [Issue]}}}
```

`Issue` is `{"code", "pointer", "source"}`: `pointer` into the resolved file, `source` the place in the file the value
came from (`/environments/staging/units/web/env/X` when the override set it). Issues compare as sets; messages are
free text and not compared. `resolved` is `null` when the merge stops (`environment.unknown`, `override.type`).

## Who runs them

- **Control plane (Pest)**: `control-plane/modules/Config/tests/Unit/SchemaCasesTest.php`, with symfony/yaml and
  opis/json-schema (`modules/Config/tests/Support/ConfigCases.php` holds the reading rules). Until the phase 1 planner
  exists it checks that resolve inputs and every expected resolved file are valid.
  `cd control-plane && vendor/bin/pest modules/Config/tests`
- **Agent / CLI (Go)**: `agent/internal/configspec` (yaml.v3, santhosh-tekuri/jsonschema/v6). It embeds a copy of
  `v1.schema.json` (go:embed can't leave the Go module); both suites fail when the copy differs. It runs every case,
  including the merge and reference checks of the resolve cases.
  `cd agent && go test ./internal/configspec/`

After editing the schema: `cp contracts/config/v1.schema.json agent/internal/configspec/v1.schema.json`.

## Rules for new cases

- One reason to fail per invalid case, said in its first comment line.
- Patterns stay inside the regex syntax both engines share (RE2 and PCRE): no lookarounds, no backreferences.
- After v0.11.0 the schema only gains optional fields: a valid case never becomes invalid.
