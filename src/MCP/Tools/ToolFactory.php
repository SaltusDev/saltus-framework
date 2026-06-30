<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ToolFactory {

	/**
	 * @return list<class-string<ToolInterface>>
	 */
	public static function defaultToolClasses(): array {
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

	public static function createDefaultProvider(): ToolProvider {
		$provider = new ToolProvider();

		foreach ( self::defaultToolClasses() as $class ) {
			$provider->register( new $class() );
		}

		return $provider;
	}
}
