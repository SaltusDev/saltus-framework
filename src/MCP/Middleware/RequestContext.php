<?php
namespace Saltus\WP\Framework\MCP\Middleware;

/**
 * Mutable request context that flows through the middleware pipeline.
 *
 * Carries raw MCP tool arguments, the built REST request (populated after
 * validation), the response from dispatch, and route/tool metadata.
 * @api
 */
class RequestContext {

	/** @var array<string, mixed> */
	private array $args = [];

	private ?\WP_REST_Request $rest_request = null;

	/** @var \WP_REST_Response|\WP_Error|null */
	private $response = null;

	/** @var array<string, mixed> */
	private array $attributes = [];

	private string $route = '';

	/** @var array<string, mixed> */
	private array $tool_metadata = [];

	/**
	 * Get the raw request arguments (MCP tool args or REST params).
	 *
	 * @return array<string, mixed>
	 */
	public function get_args(): array {
		return $this->args;
	}

	/**
	 * Set the raw request arguments.
	 *
	 * @param array<string, mixed> $args
	 */
	public function set_args( array $args ): void {
		$this->args = $args;
	}

	/**
	 * Get the built REST request, if available.
	 *
	 * @return \WP_REST_Request|null
	 */
	public function get_rest_request(): ?\WP_REST_Request {
		return $this->rest_request;
	}

	/**
	 * Set the built REST request.
	 *
	 * @param \WP_REST_Request|null $request
	 */
	public function set_rest_request( ?\WP_REST_Request $request ): void {
		$this->rest_request = $request;
	}

	/**
	 * Get the response after dispatch, if available.
	 *
	 * @return \WP_REST_Response|\WP_Error|null
	 */
	public function get_response() {
		return $this->response;
	}

	/**
	 * Set the response after dispatch.
	 *
	 * @param \WP_REST_Response|\WP_Error|null $response
	 */
	public function set_response( $response ): void {
		$this->response = $response;
	}

	/**
	 * Get all route/tool attributes.
	 *
	 * @return array<string, mixed>
	 */
	public function get_attributes(): array {
		return $this->attributes;
	}

	/**
	 * Set all route/tool attributes at once.
	 *
	 * @param array<string, mixed> $attributes
	 */
	public function set_attributes( array $attributes ): void {
		$this->attributes = $attributes;
	}

	/**
	 * Get a single attribute value.
	 *
	 * @param string $key
	 * @param mixed $default_value
	 * @return mixed
	 */
	public function get_attribute( string $key, $default_value = null ) {
		return $this->attributes[ $key ] ?? $default_value;
	}

	/**
	 * Set a single attribute value.
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	public function set_attribute( string $key, $value ): void {
		$this->attributes[ $key ] = $value;
	}

	/**
	 * Get the REST route string.
	 *
	 * @return string
	 */
	public function get_route(): string {
		return $this->route;
	}

	/**
	 * Set the REST route string.
	 *
	 * @param string $route
	 */
	public function set_route( string $route ): void {
		$this->route = $route;
	}

	/**
	 * Get the MCP tool metadata (name, parameters, etc.).
	 *
	 * @return array<string, mixed>
	 */
	public function get_tool_metadata(): array {
		return $this->tool_metadata;
	}

	/**
	 * Set the MCP tool metadata.
	 *
	 * @param array<string, mixed> $metadata
	 */
	public function set_tool_metadata( array $metadata ): void {
		$this->tool_metadata = $metadata;
	}
}
