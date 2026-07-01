<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * Factory that provides the default set of MCP tool classes and creates a ToolProvider.
 */
class ToolFactory {

	/**
	 * Get the list of default tool class names.
	 *
	 * @return list<class-string<ToolInterface>>
	 */
	public static function default_tool_classes(): array {
		return [
			ListModels::class,
			GetModel::class,
			ListPosts::class,
			GetPost::class,
			CreatePost::class,
			UpdatePost::class,
			DeletePost::class,
			ListTerms::class,
			CreateTerm::class,
			DuplicatePost::class,
			ExportPost::class,
			GetSettings::class,
			UpdateSettings::class,
			ReorderPosts::class,
			ListMetaFields::class,
			GetMetaFields::class,
		];
	}

	/**
	 * Create a ToolProvider pre-populated with all default tool classes.
	 *
	 * @return ToolProvider  Provider with all default tools registered.
	 */
	public static function create_default_provider(): ToolProvider {
		$provider = new ToolProvider();

		foreach ( self::default_tool_classes() as $class ) {
			$provider->register( new $class() );
		}

		return $provider;
	}
}
