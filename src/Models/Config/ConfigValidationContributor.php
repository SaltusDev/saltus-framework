<?php

namespace Saltus\WP\Framework\Models\Config;

/**
 * A feature that owns the validation rules for its own config section.
 *
 * The third of the framework's contributor contracts, beside `RestRouteProvider`
 * and `ToolContributor`, and collected the same way by `Core`. The reason is the
 * same too: before this existed, `ConfigValidator` reached into features for their
 * rules — importing `RelationshipDefinition`, `CodestarMeta`, `FieldPermissionPolicy`
 * — so adding a feature meant editing the validator, and the validator grew a
 * method per feature forever.
 *
 * A contributor must be constructible outside WordPress. `wp saltus config validate`
 * and CI validate without a boot, so a rule that needs a live site cannot live here
 * — those belong in the dispatch-time check, where the feature already has its args.
 *
 * @api
 */
interface ConfigValidationContributor {

	/**
	 * The top-level config key this contributor owns, e.g. `relationships`.
	 *
	 * One section per contributor. Two contributors claiming the same section is a
	 * programming error, not a merge — `ConfigValidator` reports it rather than
	 * silently letting one win.
	 */
	public function get_config_section(): string;

	/**
	 * Validate this contributor's section.
	 *
	 * Returns problems rather than a boolean so severity survives: an error stops
	 * the model registering, a warning does not, and both carry the key path and
	 * accepted values a developer needs. An empty list means the section is fine.
	 *
	 * Receives the raw config value, which may be any type — a section the author
	 * wrote as a string where an array belongs is exactly the case worth reporting.
	 *
	 * @param mixed  $value      Raw value at this contributor's section key.
	 * @param string $model_name Model name, for reporting.
	 * @return list<\Saltus\WP\Framework\Models\ConfigError>
	 */
	public function validate_config_section( $value, string $model_name ): array;

	/**
	 * Schema fragment describing this section.
	 *
	 * Feeds both the generated reference and the nearest-match suggestions, so a
	 * section that returns nothing here disappears from the docs while still being
	 * validated — which a parity test catches.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config_schema(): array;
}
