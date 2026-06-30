<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ToolFactory {

	/**
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

	public static function create_default_provider(): ToolProvider {
		$provider = new ToolProvider();

		foreach ( self::default_tool_classes() as $class ) {
			$provider->register( new $class() );
		}

		return $provider;
	}
}
