<?php

namespace Saltus\WP\Framework\Models;

interface Model {

	/**
	 * Setup the data needed to register
	 *
	 */
	public function setup(): void;

	/**
	 * Get the registration name for the model.
	 *
	 * @return string The post type or taxonomy slug.
	 */
	public function get_name(): string;

	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type(): string;

	/**
	 * Get the model registration options.
	 *
	 * @return array<string, mixed>
	 */
	public function get_options(): array;

	/**
	 * Get the model registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function get_args(): array;
}
