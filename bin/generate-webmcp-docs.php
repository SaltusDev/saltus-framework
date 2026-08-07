#!/usr/bin/env php
<?php
/**
 * Generate the WebMCP tool reference from the public tool classes.
 *
 * The generated table is derived from src/WebMcp/Tools through the same
 * ManifestBuilder the browser receives, so the documented schema is the schema
 * an agent is actually offered. Edit names, descriptions, and parameters in
 * source, then run `composer docs:webmcp`.
 */

declare( strict_types=1 );

use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\ResultBudget;
use Saltus\WP\Framework\WebMcp\ToolDescriptor;
use Saltus\WP\Framework\WebMcp\Tools\PublicTool;

$root = dirname( __DIR__ );
require_once $root . '/vendor/autoload.php';

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		return $value;
	}
}

$descriptors = build_descriptors( $root . '/src/WebMcp/Tools' );

replace_generated_section(
	$root . '/docs/guides/webmcp.md',
	'<!-- BEGIN AUTO-GENERATED WEBMCP TOOLS -->',
	'<!-- END AUTO-GENERATED WEBMCP TOOLS -->',
	build_reference( $descriptors )
);

echo 'Generated WebMCP tool docs for ' . count( $descriptors ) . " tools.\n";

/**
 * Instantiate every public tool and project it through ManifestBuilder.
 *
 * The tools need a Modeler and a policy, neither of which can boot outside
 * WordPress. Descriptors depend only on the tools' static metadata, so an
 * uninitialized Modeler and an empty policy are sufficient here.
 *
 * @return list<ToolDescriptor>
 */
function build_descriptors( string $tool_dir ): array {
	$files = glob( $tool_dir . '/*.php' );
	if ( $files === false ) {
		return [];
	}

	sort( $files );

	$modeler = ( new ReflectionClass( Modeler::class ) )->newInstanceWithoutConstructor();
	$policy  = new WebMcpPolicy( null );
	$builder = new ManifestBuilder();

	$descriptors = [];

	foreach ( $files as $file ) {
		$class = 'Saltus\\WP\\Framework\\WebMcp\\Tools\\' . basename( $file, '.php' );
		if ( ! class_exists( $class ) ) {
			continue;
		}

		$reflection = new ReflectionClass( $class );
		if ( ! $reflection->isInstantiable() || ! $reflection->isSubclassOf( PublicTool::class ) ) {
			continue;
		}

		/** @var PublicTool $tool */
		$tool       = new $class( $modeler, $policy );
		$descriptor = $builder->describe( $tool );

		if ( $descriptor instanceof ToolDescriptor ) {
			$descriptors[] = $descriptor;
		}
	}

	usort(
		$descriptors,
		static function ( ToolDescriptor $a, ToolDescriptor $b ): int {
			return strcmp( $a->get_name(), $b->get_name() );
		}
	);

	return $descriptors;
}

/**
 * Build the generated reference section.
 *
 * @param list<ToolDescriptor> $descriptors Projected descriptors.
 */
function build_reference( array $descriptors ): string {
	$lines = [
		'These tools are registered on public frontend views for every model with',
		'`webmcp: { enabled: true, frontend: true }`. All of them are read-only and return',
		'only published, publicly-visible content.',
		'',
		'| Tool | Description | Parameters |',
		'|---|---|---|',
	];

	foreach ( $descriptors as $descriptor ) {
		$lines[] = '| `' . $descriptor->get_name() . '` | '
			. escape_cell( $descriptor->get_description() ) . ' | '
			. parameter_summary( $descriptor ) . ' |';
	}

	foreach ( $descriptors as $descriptor ) {
		$lines = array_merge( $lines, build_tool_section( $descriptor ) );
	}

	$lines[] = '';
	$lines[] = '### Budgets applied to every tool';
	$lines[] = '';
	$lines[] = '| Limit | Value | Applied by |';
	$lines[] = '|---|---|---|';
	$lines[] = '| Tool name | ' . ManifestBuilder::MAX_NAME . ' characters | `ManifestBuilder` — a longer name is rejected, not truncated |';
	$lines[] = '| Tool description | ' . ManifestBuilder::MAX_DESCRIPTION . ' characters | `ManifestBuilder` — truncated on a word boundary |';
	$lines[] = '| Parameter description | ' . ManifestBuilder::MAX_PARAM_DESCRIPTION . ' characters | `ManifestBuilder` — truncated on a word boundary |';
	$lines[] = '| Serialized result | ' . ResultBudget::MAX_OUTPUT . ' characters | `ResultBudget` — entries dropped, then strings clipped |';

	return implode( "\n", $lines );
}

/**
 * Build the per-tool detail section.
 *
 * @param ToolDescriptor $descriptor Descriptor to document.
 * @return list<string>
 */
function build_tool_section( ToolDescriptor $descriptor ): array {
	$schema     = $descriptor->get_input_schema();
	$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
	$required   = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];

	$lines = [
		'',
		'### `' . $descriptor->get_name() . '`',
		'',
		$descriptor->get_description(),
		'',
	];

	if ( $properties === [] ) {
		$lines[] = 'Takes no parameters.';

		return $lines;
	}

	$lines[] = '| Parameter | Type | Required | Description |';
	$lines[] = '|---|---|---|---|';

	foreach ( $properties as $name => $definition ) {
		$definition = is_array( $definition ) ? $definition : [];
		$type       = (string) ( $definition['type'] ?? 'string' );

		if ( isset( $definition['enum'] ) && is_array( $definition['enum'] ) ) {
			$type .= ' (' . implode( ', ', array_map( 'strval', $definition['enum'] ) ) . ')';
		}

		$lines[] = '| `' . $name . '` | ' . $type . ' | '
			. ( in_array( $name, $required, true ) ? 'yes' : 'no' ) . ' | '
			. escape_cell( (string) ( $definition['description'] ?? '' ) ) . ' |';
	}

	return $lines;
}

/**
 * Summarize a descriptor's parameters for the overview table.
 */
function parameter_summary( ToolDescriptor $descriptor ): string {
	$schema     = $descriptor->get_input_schema();
	$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
	$required   = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];

	if ( $properties === [] ) {
		return '—';
	}

	$names = [];
	foreach ( array_keys( $properties ) as $name ) {
		$names[] = '`' . $name . '`' . ( in_array( $name, $required, true ) ? '\*' : '' );
	}

	return implode( ', ', $names );
}

/**
 * Escape a value for use inside a markdown table cell.
 */
function escape_cell( string $value ): string {
	return str_replace( '|', '\\|', $value );
}

function replace_generated_section( string $path, string $start, string $end, string $replacement ): void {
	$content = file_get_contents( $path );
	if ( $content === false ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}

	$start_pos = strpos( $content, $start );
	$end_pos   = strpos( $content, $end );

	if ( $start_pos === false || $end_pos === false || $end_pos <= $start_pos ) {
		throw new RuntimeException( 'Generated section markers not found in ' . $path );
	}

	$new_content = substr( $content, 0, $start_pos + strlen( $start ) )
		. "\n"
		. $replacement
		. "\n"
		. substr( $content, $end_pos );

	write_file_if_changed( $path, $new_content );
}

function write_file_if_changed( string $path, string $content ): void {
	if ( file_exists( $path ) && file_get_contents( $path ) === $content ) {
		return;
	}

	if ( file_put_contents( $path, $content ) === false ) {
		throw new RuntimeException( 'Unable to write ' . $path );
	}
}
