<?php
namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

interface HasAssets {
	public function set_assets_list(): void;
	public function register_assets(): void;
}
