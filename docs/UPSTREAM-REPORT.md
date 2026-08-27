# Ten bugs in Saltus Framework, with suggested fixes

**Reported by:** the `framework-demo` plugin, 2026-08-10, extended 2026-08-20
**Framework version:** `dev-feature/mcp-v1` @ `90132eb9eec83bbe6bdd6ab05805333d1a99035b`
**Line numbers:** from `src/` in the framework repo (not the demo's Strauss-prefixed copy)
**Environment:** WordPress with WP-CLI 2.x, PHP 8.5.4

Every bug here was found by running the framework rather than by reading it, and each is reproducible
from a clean install. Bugs 1-3 were reported on 2026-08-10. Bugs 4-10 were tracked in the demo's roadmap
over the same period and are written up here on 2026-08-20; each was re-verified against the ref above
before being added, and all seven were confirmed present.

Bug numbers are stable — 1-3 keep the numbering they were first reported under. Blast radius does not
follow that order, so this is the order worth reading in:

| # | Bug | Severity | What it costs you |
|---|-----|----------|-------------------|
| 4 | One malformed model file takes down the whole site | high | Every HTTP request 500s, wp-admin included, so the screen you would fix it from is also down |
| 1 | Every `wp` command fatals when a Saltus plugin is active | high | All of WP-CLI, so no CLI tooling or CI step can run |
| 5 | `is_multiple()` types a file from its first key | high | A single-model file whose first key is array-valued fatals the site; siblings are dropped in silence |
| 6 | Every plugin writes the same settings row | high | Two framework plugins share settings while active, and either one's uninstall deletes the other's data |
| 7 | The `active` model key is inverted | high | `active: false` registers anyway, and `active: 'true'` from YAML silently does not |
| 2 | `/health` and the audit cron query a table that may not exist | high | A 200 response hiding a DB error |
| 8 | The asset base URL is hardcoded to a path the package does not occupy | medium | Admin JS and CSS 404 on any non-default install layout |
| 3 | `shortcode_alias` does not bind to its model | medium | The documented usage renders nothing |
| 9 | The per-model `require_human_review` config is parsed and then ignored | medium | Every mutation queues on every model; the key that should decide it is dead and the filter cannot see the model |
| 10 | An unknown field type is coerced to `'string'` | low | A misspelled `type` reaches MCP clients as a valid string field |

Four of these are one-line or few-line fixes: 7, 8, 10 and the guard in 4.

---

## 1. Every `wp` command fatals when a Saltus plugin is active

**Severity: high.** Not "`wp saltus` is unavailable" — *all* of WP-CLI is unusable. `wp option get home`,
`wp user list`, `wp db query`, `wp plugin list` all fail identically.

### Reproduce

Activate any plugin built on the framework, then run any `wp` command:

```
$ wp option get home
PHP Fatal error:  Uncaught Exception: 'wp saltus' can't have subcommands.
  in phar:///usr/local/bin/wp/vendor/wp-cli/wp-cli/php/class-wp-cli.php:586
#0 …/src/Features/WpCli/WordPressCliGateway.php(6): WP_CLI::add_command()
#1 …/src/Features/WpCli/WpCli.php(43): WordPressCliGateway->add_command()
#2 …/wp-includes/class-wp-hook.php(341): WpCli->register_commands()
```

The throw happens inside `cli_init`, during `wp-settings.php`, so WordPress never finishes loading and no
command runs. The only workaround is `wp --skip-plugins`, which also disables the very commands the
framework is adding.

### Cause

One method: `SaltusCommand::__invoke()` at `src/Features/WpCli/Commands/SaltusCommand.php:14`.

WP-CLI decides a command's *kind* by reflection, in `WP_CLI\Dispatcher\CommandFactory::create()`:

```php
} elseif ( $reflection->hasMethod( '__invoke' ) ) {
    $command = self::create_subcommand( $parent, $name, [ $class, '__invoke' ], … );
} else {
    $command = self::create_composite_command( $parent, $name, $callable );
}
```

A class with `__invoke()` becomes a `Subcommand`, whose `can_have_subcommands()` returns `false`
(`Dispatcher/Subcommand.php:52`). So `WpCli.php:42` registers `saltus` as a **leaf**, and `WpCli.php:43`
immediately tries to nest `saltus model` beneath it — which `class-wp-cli.php:585` rejects.

`ReorderCommand` also defines `__invoke()`, and that one is correct: `saltus reorder` has no children.
`saltus` is the only parent in `register_commands()` with children, and the only one that must not be
invocable.

### Suggested fix

Drop `__invoke()` and expose the same behaviour as a named subcommand. WP-CLI then builds a
`CompositeCommand`, and `wp saltus` with no arguments prints the subcommand list, which is the
conventional behaviour for a namespace.

```diff
--- a/src/Features/WpCli/Commands/SaltusCommand.php
+++ b/src/Features/WpCli/Commands/SaltusCommand.php
@@
 final class SaltusCommand extends AbstractCommand {
-    /**
-     * @param list<string> $args Positional arguments.
-     * @param array<string, mixed> $assoc_args Associative arguments.
-     */
-    public function __invoke( array $args, array $assoc_args ): void {
-        $this->health( $args, $assoc_args );
-    }
-
     /**
+     * Report framework health.
+     *
+     * Deliberately not `__invoke()`. WP-CLI's CommandFactory turns any class with `__invoke()` into a
+     * Subcommand, which cannot have children — and `saltus` is the parent of nine subcommands, so an
+     * invocable parent makes `add_command( 'saltus model', … )` throw during `cli_init` and takes the
+     * whole CLI down with it.
+     *
      * @param list<string> $args Positional arguments.
      * @param array<string, mixed> $assoc_args Associative arguments.
      */
     public function health( array $args, array $assoc_args ): void {
```

**Verified** against WP-CLI's own `CommandFactory`, by registering two otherwise identical classes:

| Class | Becomes | `can_have_subcommands()` |
|-------|---------|--------------------------|
| has `__invoke()` | `WP_CLI\Dispatcher\Subcommand` | **no** |
| no `__invoke()` | `WP_CLI\Dispatcher\CompositeCommand` | **yes** |

So removing the method is sufficient — nothing else in `register_commands()` needs to change.

`wp saltus health` then works and `wp saltus` lists the tree. If a bare `wp saltus` must stay meaningful,
the alternative is registering health explicitly:

```php
$this->cli->add_command( 'saltus health', [ new SaltusCommand( $this->cli, $resolver ), 'health' ] );
```

…and *not* registering the bare `saltus` name at all — WP-CLI creates the parent namespace implicitly
from the first `saltus <child>` registration.

### Regression test worth adding

Assert that no command registered as a parent is invocable. A unit test over
`WpCli::register_commands()` can catch this without WP-CLI loaded: collect the registered names, and for
any name that is a prefix of another, assert its class has no `__invoke()`.

---

## 2. `/health` and the audit cron query a table that may not exist

**Severity: high** (silent, and on every read).

### Reproduce

On a fresh install where no MCP *write* has happened yet, request the health endpoint:

```
GET /wp-json/saltus-framework/v1/health
```

Response is `200` with `audit.error_rate: 0`, and the error log contains:

```
WordPress database error Table 'db.wp_saltus_mcp_audit' doesn't exist for query
SELECT * FROM wp_saltus_mcp_audit ORDER BY id DESC LIMIT 100
made by … HealthController->get_item, AuditLogger->get_recent_entries
```

With `WP_DEBUG_DISPLAY` on, that lands in the response body and breaks JSON parsing for the client.

### Cause

Table creation is lazy and attached only to the *write* path. `AuditLogger::record()` calls
`ensure_db()` at `src/MCP/Audit/AuditLogger.php:36`; `ensure_db()` creates the table and stamps
`saltus_mcp_audit_db_version`.

Two other methods touch the table with no such guard:

| Method | Line | Called from |
|--------|------|-------------|
| `get_recent_entries()` | `AuditLogger.php:67` | `HealthController::get_item()` — every health request |
| `cleanup_expired_entries()` | `AuditLogger.php:~138` | the `saltus_framework_mcp_audit_cleanup` cron event |

So on any install that has never recorded an audit entry, the first health read fails, and the daily cron
fails silently forever. Confirmed on this install: neither the option nor the table exists, yet the cron
event is scheduled.

### Suggested fix

Either guard both read paths, or — better — create the table on activation so the schema does not depend
on traffic order.

Minimal change:

```diff
--- a/src/MCP/Audit/AuditLogger.php
+++ b/src/MCP/Audit/AuditLogger.php
 	public function get_recent_entries( int $limit = 100 ): array {
+		// The table is created lazily by record(); a site that has only ever *read* has no table yet.
+		$this->ensure_db();
+
 		$wpdb = $this->wpdb();
```

…and the same two lines in `cleanup_expired_entries()`.

Preferable, if there is an activation hook available: call `ensure_table()` from the framework's
activation routine and keep `ensure_db()` as the fallback for existing installs. That turns a
traffic-order dependency into a deterministic one.

Worth considering either way: `get_recent_entries()` returning `[]` for a missing table is arguably
correct, but it should be a deliberate "no data yet" rather than a `$wpdb` error the caller cannot see.

**Not verified.** Unlike bugs 1 and 3, the suggested fix here was not executed — testing it means editing
the vendored framework copy, which is not ours to modify. The *bug* is confirmed live (error text above,
and `saltus_mcp_audit_db_version` absent on an install whose cron event is scheduled); the patch is
reasoned from the code rather than run.

---

## 3. `shortcode_alias` does not bind to its model

**Severity: medium** (the documented usage silently renders nothing).

### Reproduce

Configure a model with an alias:

```php
'frontend' => [
    'shortcode'       => true,
    'shortcode_alias' => 'books',
],
```

Then:

| Shortcode | Output |
|-----------|--------|
| `[books]` | **empty string** |
| `[books type="book"]` | 1,540 bytes |
| `[saltus_cpt type="book"]` | 1,540 bytes |

### Cause

`SaltusFrontend::process()` registers the alias with the *generic* callback
(`src/Features/Frontend/SaltusFrontend.php:58`):

```php
$alias = $this->config['shortcode_alias'];
if ( $alias !== '' ) {
    add_shortcode( $alias, [ self::class, 'shortcode' ] );
}
```

and that callback resolves the model from the attribute alone (`:91`):

```php
$type = sanitize_key( (string) ( $attributes['type'] ?? '' ) );
if ( $type === '' || ! isset( self::$models[ $type ] ) ) {
    return '';
}
```

The alias is registered *per model* and therefore already knows its post type — but it discards that and
requires the caller to repeat it. So `[books]` is not shorthand for anything, and the failure is an empty
string rather than an error.

### Suggested fix

Bind the alias to the model it was declared on, by defaulting `type`:

```diff
--- a/src/Features/Frontend/SaltusFrontend.php
+++ b/src/Features/Frontend/SaltusFrontend.php
 		$alias = $this->config['shortcode_alias'];
 		if ( $alias !== '' ) {
-			add_shortcode( $alias, [ self::class, 'shortcode' ] );
+			/*
+			 * The alias defaults `type` to the model that declared it, so `[books]` works. Without this
+			 * the alias saves nothing over `[saltus_cpt type="book"]` and a bare `[books]` renders an
+			 * empty string.
+			 */
+			$model_name = $this->name;
+			add_shortcode(
+				$alias,
+				static function ( $attributes = [] ) use ( $model_name ): string {
+					$attributes = is_array( $attributes ) ? $attributes : [];
+					$attributes['type'] = $attributes['type'] ?? $model_name;
+
+					return self::shortcode( $attributes );
+				}
+			);
 		}
```

**Verified** by running both registration styles against the framework's own resolution rule:

| Shortcode | Current | With the fix |
|-----------|---------|--------------|
| `[books]` | *(empty)* | renders `book` |
| `[books type="book"]` | renders `book` | renders `book` |
| `[books type="movie"]` | renders `movie` | renders `movie` |
| `[books type="bogus"]` | *(empty)* | *(empty)* |

An explicit `type` still wins, so `[books type="movie"]` keeps working if anyone relies on it, and an
unknown type still refuses rather than falling back to the alias's own model.

Separately worth considering: `shortcode()` returning `''` for an unknown type makes every
misconfiguration invisible. A `WP_DEBUG`-gated comment (`<!-- saltus: unknown type "x" -->`) would cost
nothing in production and save the next person the bisect.

---

## 4. One malformed model file takes down the whole site

**Severity: high.** Not "the broken model does not load" — every HTTP request returns 500, front end and
wp-admin alike, so the admin screen you would use to fix it is also down and recovery needs filesystem
access. This is the widest blast radius in the report: bug 1 disables WP-CLI, this disables the site. The
trigger is an ordinary authoring mistake rather than a contrived input.

### Reproduce

Drop a model file with one unclosed bracket into a plugin's `src/models/`:

```php
<?php
return [
	'name' => 'probe',
	'type' => 'cpt',
```

Then request any page. Observed on a live install:

```
before (clean)          : HTTP 200 81690
with broken model file  : HTTP 500 3136   (home)
with broken model file  : HTTP 500 3312   (wp-admin)
after removing the file : HTTP 200 81690
```

The response body is the raw parse error:

```
Parse error: Unclosed '[' on line 2 in .../src/models/zzz-broken-probe.php on line 5
```

Valid model files alongside it do not save the request. Driving the real `Modeler::init()` over a corpus
containing one bad file and one good one, every failure mode kills the process before any model registers:

```
baseline_good     rc=0    SITE_DOWN=False  loaded=[good]
syntax_error      rc=255  SITE_DOWN=True   PHP Parse error:  Unclosed '[' on line 2
redeclare         rc=255  SITE_DOWN=True   PHP Fatal error:  Cannot redeclare saltus_dupe_helper()
exit_call         rc=3    SITE_DOWN=True   (no output past PRE-INIT)
bad_json          rc=255  SITE_DOWN=True   Uncaught Noodlehaus\Exception\ParseException: Syntax error
returns_string    rc=255  SITE_DOWN=True   Uncaught UnsupportedFormatException: PHP data does not return an array
undefined_class   rc=255  SITE_DOWN=True   Uncaught Error: Class "NoSuchClass" not found
throws_exception  rc=255  SITE_DOWN=True   Uncaught RuntimeException: model blew up
```

### Cause

`src/Modeler.php:114`, in `Modeler::load()`:

```php
113:			foreach ( $files as $file ) { // Iterate over sorted files
114:				$config = new Config( $file );
115:				$this->process_config( $config );
116:			}
```

`new Config( $file )` is unguarded, and there is no `try` anywhere in `Modeler::load()` (`:92`),
`Modeler::init()` (`:52-61`) or `ModelFactory::create()` (`src/Models/ModelFactory.php:41`). Whatever the
file throws propagates out of the `init` callback. `src/Core.php:158-161` attaches the load to `init`,
which fires on every request — front end, admin, REST, cron — so the fatal is unconditional rather than
scoped to one route.

A contributing defect lives in the config library, and it is why the guard belongs at the framework's own
callsite rather than upstream of it. `vendor/hassankhan/config/src/Parser/Php.php:31-33`:

```php
31:        try {
32:            $data = require $filename;
33:        } catch (Exception $exception) {
```

`ParseError` and `Error` extend `Throwable`, not `Exception`, so they walk straight past this handler.
Separately, `vendor/hassankhan/config/src/Parser/Php.php:95` throws `UnsupportedFormatException` from `parse()`, which is called after the
try block closes, so a file returning a non-array is unguarded even against `Exception`.

**A `ParseError` from `require` is catchable**, which is worth stating because it is commonly assumed not
to be. Same file, three ways:

```
A_bare_require_unclosed         rc=0    CAUGHT ParseError / alive
B_via_Config_no_catch           rc=255  Parse error (fatal)
C_via_Config_catch_throwable    rc=0    CAUGHT ParseError / alive
```

So the fatal is the framework's to prevent, not a PHP limitation.

### Suggested fix

Guard the construction and skip the file:

```php
foreach ( $files as $file ) { // Iterate over sorted files
	/*
	 * A model file is authored by hand and `require`d on `init`, on every request. One syntax
	 * error therefore fatals the entire site — including the admin screen needed to fix it —
	 * unless it is contained here.
	 *
	 * `Throwable`, not `Exception`: a `require` of an unparseable file raises `ParseError`,
	 * which extends `Error`. The config library catches only `Exception`, so it lets
	 * `ParseError` through.
	 */
	try {
		$config = new Config( $file );
	} catch ( \Throwable $exception ) {
		$this->skipped_files[ $file ] = sprintf(
			'%s: %s',
			( new \ReflectionClass( $exception ) )->getShortName(),
			$exception->getMessage()
		);

		error_log( sprintf( 'Saltus: skipping model file %s — %s', $file, $exception->getMessage() ) );
		continue;
	}

	$this->process_config( $config );
}
```

With a public accessor so the failure is reportable rather than merely survived:

```php
/** @var array<string, string> Model files skipped this request, path => reason. */
protected array $skipped_files = [];

/**
 * Model files that failed to load this request.
 *
 * Surfaced so an admin notice, the health endpoint, or WP-CLI can report a broken
 * model instead of leaving it to be inferred from a missing post type.
 *
 * @return array<string, string> Path => failure reason.
 */
public function get_skipped_files(): array {
	return $this->skipped_files;
}
```

Validated against the same seven fixtures, current code versus patched:

```
syntax_error      CURRENT rc=255 down=True | PATCHED rc=0 down=False loaded=[good] SKIPPED ParseError: Unclosed '['
bad_json          CURRENT rc=255 down=True | PATCHED rc=0 down=False loaded=[good] SKIPPED ParseException: Syntax error
returns_string    CURRENT rc=255 down=True | PATCHED rc=0 down=False loaded=[good] SKIPPED UnsupportedFormatException
undefined_class   CURRENT rc=255 down=True | PATCHED rc=0 down=False loaded=[good] SKIPPED Error: Class not found
throws_exception  CURRENT rc=255 down=True | PATCHED rc=0 down=False loaded=[good] SKIPPED ParseException
```

Five of seven contained, including the syntax error that motivates the ask, and in every contained case
the valid models still register — the site stays up with one model missing instead of going down.

Redeclare and top-level `exit`/`die` are genuinely uncatchable and cannot be fixed at this callsite; they
need static prevention. That split is worth stating plainly: the framework can own five of the seven
modes, and a linter owns the rest.

### Note on where this fix already runs

The demo implements exactly this shape at its own include site — `src/Plugin/Studio/ModelReader.php:266-280`
wraps `include $path` in `try { … } catch ( \Throwable $exception )` with a non-array check after it — and
it works. `Modeler.php:114` is the same operation without the guard. The fix is not speculative.

---

## 5. `Modeler::is_multiple()` types a model file from its first key

**Severity: high.** The visible half is a silently dropped model. The half that matters more is that key
*order* decides the parse, so an ordinary single-model file whose first key happens to be array-valued —
`labels` before `type` — fatals the `init` hook and takes the site down. Nothing in the file is mixed or
unusual; it is only written in a different order.

### Reproduce

Four fixtures through the real `Modeler::init()`, with `register_post_type()`/`register_taxonomy()`
stubbed to record calls:

```
== a-mixed-scalar-first.php   ('type' => 'cpt', 'name' => …, 'event_category' => array( … ))
   current() is   : string
   is_multiple()  : false (single)
   models declared: mix_event, mix_event_cat
   register* calls: post_type:mix_event
   error          : (none)                      <- sibling dropped in silence

== b-mixed-array-first.php    (same file, 'event_category' moved first)
   current() is   : array
   is_multiple()  : true (list)
   register* calls: taxonomy:mix_cat_first      <- registers the wrong one, drops the CPT
   error          : TypeError: AbstractConfig::__construct(): Argument #1 ($data)
                    must be of type array, string given, called in .../src/Modeler.php on line 178

== c-list.php                 (two model arrays, string keys)
   is_multiple()  : true (list)
   register* calls: taxonomy:mix_list_one, taxonomy:mix_list_two
   error          : (none)                      <- correct

== d-single-array-first-key.php  ('labels' => array( … ), 'type' => 'cpt', 'name' => 'mix_widget')
   current() is   : array
   is_multiple()  : true (list)
   register* calls: (none)
   error          : TypeError: AbstractConfig::__construct(): Argument #1 ($data)
                    must be of type array, string given, called in .../src/Modeler.php on line 178
```

Case (d) is the one to look at. It is a perfectly ordinary single-model file. It registers nothing and
kills the request.

### Cause

`src/Modeler.php:167-169` makes the decision:

```php
protected function is_multiple( AbstractConfig $config ): bool {
	return ( is_array( current( $config->all() ) ) );
}
```

`current()` inspects one value — whichever key was written first — and that single sample types the whole
file. `src/Modeler.php:176-180` is where a wrong verdict detonates:

```php
protected function iterate_multiple( AbstractConfig $config ): void {
	foreach ( $config as $single_config ) {
		$this->create( new NoFile( $single_config ) );
	}
}
```

`NoFile` (`src/Models/Config/NoFile.php:9`) is an empty subclass of `Noodlehaus\AbstractConfig`, whose
constructor is typed `__construct(array $data)`, so passing a scalar is an immediate `TypeError`. Typed
wrongly the other way, the config goes to `create()` as one model and every sibling model array is just an
unrecognised key: `ModelFactory::create()` reads only `type`, so the sibling vanishes with no diagnostic.

### Suggested fix

Stop sampling one value. The real discriminator is whether the config declares a model itself, which is
whether it has a `type` key — the same key `ModelFactory::create()` already requires:

```php
/**
 * Is this config a list of models, rather than one model?
 *
 * A config that declares `type` is a single model, whatever its key order.
 * A list is a config whose every top-level value is a model array.
 */
protected function is_multiple( AbstractConfig $config ): bool {
	$all = $config->all();
	if ( $all === [] ) {
		return false;
	}

	if ( array_key_exists( 'type', $all ) ) {
		$this->warn_dropped_siblings( $all );
		return false;
	}

	$arrays = 0;
	foreach ( $all as $value ) {
		if ( is_array( $value ) ) {
			++$arrays;
		}
	}

	if ( $arrays > 0 && $arrays !== count( $all ) ) {
		_doing_it_wrong(
			__METHOD__,
			'Model file mixes model arrays with scalar keys; it must declare either one model or a list of models.',
			'3.0.0'
		);
	}

	return $arrays > 0 && $arrays === count( $all );
}

/**
 * Warn when a single-model config carries a sibling that also declares `type`.
 *
 * @param array<string|int, mixed> $all Top-level config data.
 */
private function warn_dropped_siblings( array $all ): void {
	foreach ( $all as $key => $value ) {
		if ( is_array( $value ) && array_key_exists( 'type', $value ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					'Key "%s" declares a second model but its file declares a single model; it will not be registered. Move it to its own file, or make the file a list of models.',
					esc_html( (string) $key )
				),
				'3.0.0'
			);
		}
	}
}
```

And make `iterate_multiple()` non-fatal regardless, so no config can kill `init`:

```php
protected function iterate_multiple( AbstractConfig $config ): void {
	foreach ( $config as $key => $single_config ) {
		if ( ! is_array( $single_config ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf( 'Skipping non-array model entry "%s".', esc_html( (string) $key ) ),
				'3.0.0'
			);
			continue;
		}
		$this->create( new NoFile( $single_config ) );
	}
}
```

Run against the four fixtures above plus a numerically-indexed list:

```
fixture                      | current          | fixed          | want
--------------------------------------------------------------------------
A mixed, scalar first        | single           | single + warn  | single + warn
B mixed, array first         | list (FATAL)     | single + warn  | single + warn
C list of models             | list             | list           | list
D single, array first key    | list (FATAL)     | single         | single
E numeric list               | list             | list           | list
```

Key order no longer changes the verdict, both fatals become correct single-model parses, the two
legitimate list shapes are unchanged, and genuinely mixed files say so instead of dropping a model.

### Regression test worth adding

A single-model config whose first key is array-valued must parse as one model. That one case would have
caught this, and it is the case a real author hits by writing `labels` first.

---

## 6. Every framework plugin writes the same settings row

**Severity: high.** Two plugins built on the framework, both declaring a post type of the same slug, share
one `wp_options` row while active — so one plugin's settings screen silently edits the other's — and
uninstalling either deletes the row the survivor is still using. This is data loss on an uninstall that
looks like it is cleaning up after itself.

### Reproduce

Two genuinely distinct `SettingsManager` instances, one per notional plugin, asked for the same post type:

```
plugin A -> saltus_framework_settings_book
plugin B -> saltus_framework_settings_book
IDENTICAL: YES (collision)
```

Against the live `wp_options` table, with both consequences executed rather than inferred:

```
plugin A saves   {"per_page":10,"show_meta":true}
plugin B reads   {"per_page":10,"show_meta":true}   <- B sees A's data
plugin B saves   {"per_page":99}
plugin A reads   {"per_page":99}                    <- A's settings silently changed

plugin B uninstalls (delete_option on its own name)
plugin A reads   (row absent)                       <- A's live settings destroyed
```

The row was absent before the probe and absent after; the DB was restored to its exact prior state.

### Cause

`src/Features/Settings/SettingsManager.php:16-18`:

```php
16:	public function option_name( string $post_type ): string {
17:		return sprintf( 'saltus_framework_settings_%s', $post_type );
18:	}
```

The name derives from `$post_type` and a hardcoded framework literal, and from nothing else. Confirmed by
reflection: one parameter, no instance state read, no filter applied. No option-name filter exists
anywhere among the framework's hooks, so a plugin has no way to qualify the name from outside.

Strauss prefixing does not save you here. It rewrites namespaces, not string literals, so two prefixed
copies of the framework still produce byte-identical option names.

### Suggested fix

Take a prefix at construction, defaulting to the current literal so existing rows keep resolving, and add
a filter for consumers that cannot reach the constructor:

```php
class SettingsManager {

	/** Default prefix, kept as the fallback so existing rows keep resolving. */
	private const DEFAULT_PREFIX = 'saltus_framework_settings_';

	private string $prefix;

	public function __construct( string $prefix = self::DEFAULT_PREFIX ) {
		$this->prefix = $prefix;
	}

	/**
	 * Build the option name for a post type's settings.
	 *
	 * The prefix is per-plugin: every plugin built on this framework would otherwise write the
	 * same row for the same post-type slug, sharing settings while active and deleting a
	 * sibling's live data on uninstall.
	 */
	public function option_name( string $post_type ): string {
		$name = $this->prefix . $post_type;

		/**
		 * Filters the option name a plugin's settings are stored under.
		 *
		 * @param string $name      The option name.
		 * @param string $post_type The post type the settings belong to.
		 * @param string $prefix    The prefix this manager was constructed with.
		 */
		return (string) apply_filters( 'saltus/framework/settings/option_name', $name, $post_type, $this->prefix );
	}
}
```

Changing the returned name is sufficient to separate the rows — verified by subclassing and observing
`my_plugin_saltus_settings_book` written and read independently of the framework-default row.

Migration matters for a released framework: consumers that adopt a prefix orphan their existing rows. A
one-time `option_name()`-aware migration, or reading the default name as a fallback when the prefixed row
is absent, would make the change safe to ship.

---

## 7. The `active` model key is inverted

**Severity: high.** This is the framework's only documented per-model kill switch and it is wrong in both
directions. `active => false` — the documented way to disable a model — registers it anyway. Meanwhile
every truthy value that is not the literal boolean `true` disables the model, so `active: true` authored in
YAML or JSON, where it can parse to the string `'true'`, silently drops a model the author meant to enable.

### Reproduce

A real `PostType` over `['type' => 'cpt', 'name' => 'probe_thing']` plus one `active` value per row, with
`register_post_type()` stubbed to record calls:

```
config               | is_disabled | registered | verdict
------------------------------------------------------------------------
active => true       | false       | YES        | ok
active => false      | false       | YES        | WRONG
active => 1          | true        | no         | WRONG
active => 0          | false       | YES        | WRONG
active => 'true'     | true        | no         | WRONG
active => 'false'    | true        | no         | ok
active => 'no'       | true        | no         | ok
active => 'yes'      | true        | no         | WRONG
active => null       | false       | YES        | ok
key absent           | false       | YES        | ok
```

Six of ten inputs behave the opposite of how they read.

### Cause

`src/Models/BaseModel.php:95-100`:

```php
protected function is_disabled(): bool {
	if ( empty( $this->data['active'] ) || $this->data['active'] === true ) {
		return false;
	}
	return true;
}
```

Two independent defects in one condition. `empty( false )` is `true`, so `'active' => false` takes the
early `return false` — not disabled — and the model loads. And the second clause only exempts the
*identical* boolean `true`, so any other truthy value falls through to `return true` and is disabled.

The guard is called from three places, all of which then skip their work: `BaseModel.php:74` (the
constructor returns before `set_name()`, leaving `$this->name` empty), `Models/PostType.php:18` and
`Models/Taxonomy.php:22` (both `setup()` return before `register()`).

### Suggested fix

```php
/**
 * Check to see if model has been disabled
 *
 * Absent or null `active` means enabled; only an explicitly falsy value disables.
 */
protected function is_disabled(): bool {
	return isset( $this->data['active'] )
		&& ! filter_var( $this->data['active'], FILTER_VALIDATE_BOOLEAN );
}
```

Re-running the identical matrix with only this body replaced gives ten of ten correct, including the
YAML-authored `'true'` and `'yes'` cases and the documented `false`.

### Regression test worth adding

The matrix above, or at minimum the two rows that read as their own opposite: `active => false` must not
register, and `active => 'true'` must.

---

## 8. The asset base URL is hardcoded to a path the package does not occupy

**Severity: medium.** One hardcoded string decides the base URL for every script and style the framework
enqueues, so it is not scoped to one feature. It breaks for any install whose framework does not sit at
exactly `<plugin>/vendor/saltus/framework/` — which includes every Strauss-prefixed consumer, any
non-default composer `vendor-dir`, and symlinked path repositories. It degrades admin JS and CSS rather
than breaking page loads, and it fails silently, which is why it survives.

### Reproduce

Booting WordPress directly and evaluating the expression, then comparing it against the location of the
`Core` class actually loaded:

```
Core.php:117 root_url   => http://saltus.test/assets/plugins/framework-demo/vendor/saltus/framework/assets/
Core.php:114 root_path (vendor)          => .../framework-demo/vendor/saltus/framework
Core.php:114 root_path (vendor-prefixed) => .../framework-demo/vendor-prefixed/saltus/framework

Running Core.php  : .../framework-demo/vendor-prefixed/saltus/framework/src/Core.php
root_url points at: .../framework-demo/vendor/saltus/framework/assets/
MATCH? NO — asset base names a different copy than the loaded code
```

The structural defect reproduces unconditionally: `root_url` names a different copy of the package than
the code that is running. Strauss rewrites namespaces, not string literals, so the prefixed
`Core.php:117` still reads `'vendor/saltus/framework/assets/'` verbatim.

Whether that produces a 404 depends on whether `vendor/saltus/framework/assets/` happens to exist. In a
shipped ZIP it does not — the build excludes `vendor` and `vendor-prefixed` from the stage, then runs
Strauss with `delete_vendor_packages: true`, leaving nothing at the hardcoded path. In a dev checkout a
later `composer install` recreates it, so the URL resolves by luck. The 404 mechanism was confirmed
separately by requesting a path under that base that genuinely does not exist:

```
$ curl -sk -o /dev/null -w '%{http_code}' .../vendor/saltus/framework/assets/Feature/DragAndDrop/does-not-exist.js
404
```

### Cause

`src/Core.php:117`, in `Core::__construct()`:

```php
// the framework root path
$this->project['root_path'] = dirname( __DIR__ );

// the 'plugin-dir' part is just to fool plugins_url to consider the full path
$this->project['root_url'] = plugins_url( 'vendor/saltus/framework/assets/', $project_path . '/plugin-dir' );
```

`root_path` on the line above already knows where the package really is. `root_url` ignores it and
reconstructs a path from an assumption instead. The comment about fooling `plugins_url` is a signal in
itself — the call is being worked around rather than used.

### Suggested fix

Derive the URL from the location the line above already computed:

```php
// the framework root path
$this->project['root_path'] = dirname( __DIR__ );

/*
 * Derive the asset base from the package's own location — `root_path` above already knows it —
 * rather than assuming the consumer installed us at `<plugin>/vendor/saltus/framework/`. That
 * assumption is wrong for a Strauss-prefixed install, for a non-default composer `vendor-dir`,
 * and for symlinked path repositories.
 */
$this->project['root_url'] = trailingslashit(
	plugin_dir_url( $this->project['root_path'] . '/placeholder' )
) . 'assets/';
```

A filter would also resolve it for consumers who cannot wait for a release:

```php
$this->project['root_url'] = (string) apply_filters(
	'saltus/framework/asset_base_url',
	$this->project['root_url'],
	$this->project['root_path']
);
```

The demo currently patches around this twice for the same root cause: a `script_loader_src` /
`style_loader_src` filter that string-replaces the wrong path segment, and a build-time patch applied
after Strauss runs. Either fix above removes the need for both.

---

## 9. The per-model `require_human_review` config is parsed and then ignored

**Severity: medium.** Every one of the eight mutating MCP tools queues for human approval on every model,
including models that configure no `ai_context` at all. The framework already reads a per-model
`require_human_review` key, already defaults it to `false`, and already exposes it — and nothing consumes
it. The queue decision is a hardcoded `true` in a different class, so the documented config key is dead
and the filter meant to override it cannot tell which model it is deciding about.

### Reproduce

A callback registered on the filter at priority 99, observing its own arguments across all eight mutating
tools:

```
func_num_args() === 2 on all eight tools     (bool $queue, string $tool)
```

The obvious routes to a model are all dead at that point in the request:

```
get_post_type()      => false
$GLOBALS['post']     => unset
get_current_screen() => not loaded (is_admin() false, REST_REQUEST undefined)
```

Meanwhile the value that should be driving the decision is sitting right there. `AiContextProvider::get()`
returns it per model, and it defaults to `false`:

```
AiContextProvider.php:50  'require_human_review' => $this->bool_value( $raw, $defaults, 'require_human_review' )
AiContextProvider.php:124 'require_human_review' => false,      <- default in defaults()
```

So a model that configures nothing resolves to `require_human_review => false`, and queues anyway. The
config default and the runtime behaviour are direct opposites.

### Cause

Two classes that never talk to each other.

`src/Features/EditorialReview/ProposalService.php:26-45`, in `should_queue()`. Non-mutating tools return
early at `:40`; the eight mutating ones reach `:43`:

```php
42:		if ( function_exists( 'apply_filters' ) ) {
43:			return (bool) apply_filters( 'saltus/framework/editorial_review/require_human_review', true, $tool );
44:		}
45:		return true;
```

The default is the literal `true`, not the model's own value, and `should_queue()` receives only
`string $tool` so it could not read the model's value even if it wanted to. Its two callers
(`src/MCP/Abilities/AbilityRuntime.php:112` and `:223`) both pass just `$tool->get_name()`, discarding the
`$args` they are holding.

Grepping the whole package for the config key confirms the gap — three hits, and no consumer among them:

```
src/Features/AiContext/AiContextProvider.php:50   parses it per model
src/Features/AiContext/AiContextProvider.php:124  defaults it to false
src/Features/EditorialReview/ProposalService.php:43  filter of the same name, hardcoded true
```

The filter shares the config key's name, which is what makes the gap easy to miss on a read: it looks
like the key is wired up.

**One correction to how we previously described this.** The post type is not strictly unreachable from a
callback. It can be recovered by exploiting dispatch ordering — `AbilityRuntime::execute_legacy()` calls
`AiContextProvider::validate_mutation()` before `should_queue()`, and `validate_mutation()` fires
`saltus/framework/ai_context/defaults` with the model name (`AiContextProvider.php:39`), which a callback
could stash and read back moments later. That works, and it is not an API: it depends on the provider
being non-null, on the model resolving at all, on nothing else firing that filter in between, on the
caller clearing the stash to avoid leaking state across requests, and on the framework never reordering
those two calls. The accurate statement is that the filter cannot supply the model, and the only route to
it is hook-ordering exploitation.

### Suggested fix

Two parts. First, make the model resolution reusable — it already exists inline at
`AiContextProvider::validate_mutation():67-73`, so lift it rather than duplicating it:

```php
/**
 * Resolve the model a tool invocation is acting on.
 *
 * Extracted from validate_mutation() so the queue decision can reach the same answer.
 *
 * @param array<string, mixed> $args
 */
public function resolve_model_name( string $tool_name, array $args ): string {
	$model_name = (string) ( $args['post_type'] ?? 'posts' );

	if ( $tool_name !== 'create_post' && function_exists( 'get_post' ) ) {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( $post ) {
			$model_name = (string) $post->post_type;
		}
	}

	return $model_name;
}
```

Then have `should_queue()` default from the model's own config instead of from `true`:

```php
private ?AiContextProvider $ai_context;

public function __construct(
	?ProposalStore $store = null,
	?AuditLogger $audit = null,
	?AiContextProvider $ai_context = null
) {
	$this->store      = $store ?? new ProposalStore();
	$this->audit      = $audit ?? new AuditLogger();
	$this->ai_context = $ai_context;
}

/**
 * @param array<string, mixed> $args The tool arguments, so the model can be resolved.
 */
public function should_queue( string $tool, array $args = [] ): bool {
	// … the mutating-tool check at :26-41 is unchanged …

	$model   = null;
	$default = true;

	if ( $this->ai_context !== null ) {
		$model   = $this->ai_context->resolve_model_name( $tool, $args );
		$context = $this->ai_context->get( $model );

		/*
		 * Honour the model's own `require_human_review`. Without this the key is parsed and
		 * discarded, and every model queues regardless of what it configures.
		 */
		if ( $context !== null ) {
			$default = (bool) $context['require_human_review'];
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		return $default;
	}

	/**
	 * Filters whether a mutation queues for editorial review.
	 *
	 * @param bool        $default Defaulted from the model's own `ai_context.require_human_review`.
	 * @param string      $tool    The tool being invoked.
	 * @param array       $args    The tool arguments.
	 * @param string|null $model   The resolved model name, or null when no provider is wired.
	 */
	return (bool) apply_filters(
		'saltus/framework/editorial_review/require_human_review',
		$default,
		$tool,
		$args,
		$model
	);
}
```

And pass the arguments at both callsites, `AbilityRuntime.php:112` and `:223`:

```php
if ( $this->proposals !== null && $this->proposals->should_queue( $tool->get_name(), $args ) ) {
```

Appending parameters keeps this backward compatible: existing two-argument callbacks and any external
`should_queue( $tool )` caller both keep working.

### A note on the default

Changing the default from `true` to the model's value is a behaviour change, and it is the point of the
fix — but it flips unconfigured models from queueing to not queueing. If that is too sharp for a minor
release, keeping `true` as the fallback *only* when `$context['configured']` is false preserves today's
behaviour for models that opted into nothing while honouring the key for models that set it:

```php
$default = $context['configured'] ? (bool) $context['require_human_review'] : true;
```

Either way the key stops being dead. We would take either.

## 10. An unknown field type is coerced to `'string'` with no diagnostic

**Severity: low.** One mistyped field per authoring mistake, and it takes an authoring mistake to trigger,
so the blast radius is a single field rather than a model or a site. It is here because the failure is
silent in the place it matters: the field reaches MCP clients described as a valid string field.

### Reproduce

A field with a misspelled type behaves differently in the two consumers, and the distinction is worth
keeping:

- **In the editor it is not silent.** Codestar resolves field classes by name and echoes `Field not found!`
  when the class is absent. Rendering both through `CSF_Setup::field()`: `type => 'text'` produced a
  224-byte `<input type="text" …>`; `type => 'tpye'` produced 167 bytes containing `<p>Field not found!</p>`.
- **In the REST and MCP schema it is silent.** The same misspelled type is reported as
  `"type": "string"`, indistinguishable from a genuine text field, so an MCP client is told the field
  exists and is writable as a string.

### Cause

`src/Features/Meta/MetaFieldProvider.php:427`, in `get_schema_type()` (`:413-428`):

```php
return $field_type_map[ $codestar_type ] ?? 'string';
```

The map lists only the types that are *not* strings, so `'string'` doubles as both a legitimate mapping and
the fallback for anything unrecognised. Those two meanings cannot be told apart, which is what makes the
failure silent.

### Suggested fix

Make the map complete, so an unrecognised type is distinguishable from a genuine string:

```php
private function get_schema_type( string $codestar_type ): string {
	/*
	 * The *complete* type map, not just the non-string entries. Listing only the exceptions means a
	 * misspelled type is indistinguishable from a genuine string field, so a typo reaches API
	 * clients as a valid field.
	 */
	$field_type_map = [
		'text'        => 'string',
		'textarea'    => 'string',
		'number'      => 'number',
		'switcher'    => 'boolean',
		'background'  => 'object',
		'color_group' => 'object',
		'fieldset'    => 'object',
		'group'       => 'object',
		'map'         => 'object',
		'media'       => 'array',
		'select'      => 'array',
		'repeater'    => 'array',
		// … the remaining supported types
	];

	if ( ! isset( $field_type_map[ $codestar_type ] ) ) {
		_doing_it_wrong(
			__METHOD__,
			sprintf(
				'Unknown field type "%s"; falling back to string. Check the field\'s `type` key.',
				esc_html( $codestar_type )
			),
			'3.0.0'
		);

		return 'string';
	}

	return $field_type_map[ $codestar_type ];
}
```

The demo's workaround is a linter (`bin/lint-models.php:399`) that checks every field type under `meta` and
`settings` against a 44-value enum generated from the framework's own field directories, run in CI. That
catches typos before they ship but cannot help a consumer who has not built the same tooling.

---

## Notes

- **Bug 4 is the one to fix first.** Bug 1 blocks tooling; bug 4 blocks the site, and the fix is a `try`
  around one line. The trigger is a single mistyped character in a hand-authored file.
- **Six of the ten are silent** — 2, 3, 5 (the dropped-sibling half), 7, 9 and 10. None raises anything a
  developer sees without checking the error log, diffing expected output, or reading the generated schema.
  That is why they survived to a live install, and it is the pattern worth taking from this report:
  `_doing_it_wrong()` in the fallback branches would have caught 5, 7 and 10 at authoring time.
- **Bugs 5 and 7 are one-condition fixes with a matrix behind them.** Each has a table above showing
  current versus fixed behaviour across every input shape; those tables are the regression tests.
- **Bug 9 is a wiring gap, not a missing feature.** The per-model key is already parsed and already
  defaulted; only the consumer is missing. Worth checking whether any other `ai_context` key has the
  same shape — we only traced `require_human_review`.
- **Bug 6 needs a migration decision, not just a code change.** Adding a prefix orphans existing rows, so
  the fix is only safe to ship with a fallback read or a one-time migration.
- **Bug 4's fix is already running in this repo**, one layer up, at `src/Plugin/Studio/ModelReader.php:266-280`.
  The framework's `Modeler.php:114` is the same operation without the guard.
- The demo plugin works around bug 1 with two credential-free probe scripts
  (`bin/probe-rest-routes.php`, `bin/probe-frontend.php`) that boot WordPress directly and never define
  `WP_CLI`. Happy to contribute those upstream as integration tests if useful.
- Every demo-side workaround named in this report is a mitigation we would rather delete. Each bug section
  says where ours lives, so you can see what the framework is currently making consumers build.
