<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\AiAssistant\AiAssistantProvider;
use Saltus\WP\Framework\Features\AiContext\AiContextProvider;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\AiAssistantController;
use Saltus\WP\Framework\Rest\AiContextController;
use Saltus\WP\Framework\Rest\BlocksController;
use Saltus\WP\Framework\Rest\DuplicateController;
use Saltus\WP\Framework\Rest\EditorialReviewController;
use Saltus\WP\Framework\Rest\ExportController;
use Saltus\WP\Framework\Rest\HealthController;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ModelsController;
use Saltus\WP\Framework\Rest\RelationshipsController;
use Saltus\WP\Framework\Rest\ReorderController;
use Saltus\WP\Framework\Rest\SettingsController;
use Saltus\WP\Framework\Rest\WebMcpController;
use WP_Error;

require_once __DIR__ . '/functions.php';

/**
 * Guards the framework's permission surface against accidental exposure.
 *
 * The failure this exists to prevent is a real one, observed in a public
 * WordPress boilerplate: a route helper defaulted its auth argument to `false`
 * and mapped that to `__return_true`, so any route written the short way was
 * public. It shipped in a tagged release and was masked only by a compensating
 * call in an unrelated file.
 *
 * Saltus does not have that hole. These tests exist so it cannot acquire one
 * quietly — the property moves from "true by habit" to "asserted".
 *
 * Deliberately public surfaces are not failures. WebMCP serves anonymous
 * visitors by design, so the rule enforced here is not "nothing may be public"
 * but "anything public must *declare* itself public through the type system".
 * See {@see testPubliclyPermissiveWebMcpToolsDeclareThemselvesPublic}.
 *
 * @covers \Saltus\WP\Framework\Rest\RestServer
 */
class PermissionSurfaceTest extends TestCase {

	/**
	 * Controllers whose permission callbacks gate on capability directly.
	 *
	 * WebMcpController is absent on purpose — its callbacks gate on feature
	 * enablement rather than capability, with enforcement inside execute_tool().
	 * It is covered separately by
	 * {@see testWebMcpExecuteEnforcementLivesInTheHandler}.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function capabilityGatedControllers(): array {
		return [
			'AiAssistantController'    => [ AiAssistantController::class ],
			'AiContextController'      => [ AiContextController::class ],
			'BlocksController'         => [ BlocksController::class ],
			'DuplicateController'      => [ DuplicateController::class ],
			'EditorialReviewController' => [ EditorialReviewController::class ],
			'ExportController'         => [ ExportController::class ],
			'HealthController'         => [ HealthController::class ],
			'MetaController'           => [ MetaController::class ],
			'ModelsController'         => [ ModelsController::class ],
			'RelationshipsController'  => [ RelationshipsController::class ],
			'ReorderController'        => [ ReorderController::class ],
			'SettingsController'       => [ SettingsController::class ],
		];
	}

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can, $wp_post_type_objects, $wp_taxonomy_objects, $wp_filter_values;

		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_post_type_objects      = [];
		$wp_taxonomy_objects       = [];
		$wp_filter_values          = [];
	}

	/**
	 * A tripwire for the literal regression, cheap and total.
	 *
	 * It cannot catch `fn() => true`, and that is fine — the shape tests below
	 * cover reachable permissiveness. This one catches the specific thing a
	 * person reaches for at 2am to make a 403 go away.
	 */
	public function testSourceContainsNoReturnTrueCallback(): void {
		$offenders = [];

		foreach ( $this->sourceFiles() as $file ) {
			$contents = (string) file_get_contents( $file );
			if ( strpos( $contents, '__return_true' ) !== false ) {
				$offenders[] = $this->relativePath( $file );
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"__return_true grants access to everyone and must never appear in src/.\n"
			. "If a route is genuinely public, gate it on a policy object and document\n"
			. "why at the call site, as WebMcpController and PublicTool do."
		);
	}

	/**
	 * Every registered route must carry a permission_callback key.
	 *
	 * WordPress treats an absent callback as public (and notices about it), so
	 * omission is the same defect as `__return_true` with less evidence.
	 *
	 * @dataProvider capabilityGatedControllers
	 */
	public function testEveryRegisteredRouteDeclaresAPermissionCallback( string $class ): void {
		global $wp_rest_routes_registered;

		$this->makeController( $class )->register_routes();

		$this->assertNotEmpty(
			$wp_rest_routes_registered,
			$class . ' registered no routes, so this test proves nothing. Update the provider.'
		);

		foreach ( $wp_rest_routes_registered as $registered ) {
			$route = $registered['namespace'] . '/' . $registered['route'];

			foreach ( $this->endpointsOf( $registered['args'] ) as $endpoint ) {
				$this->assertArrayHasKey(
					'permission_callback',
					$endpoint,
					$route . ' registers an endpoint with no permission_callback, which WordPress treats as public.'
				);
				$this->assertIsCallable(
					$endpoint['permission_callback'],
					$route . ' has a permission_callback that is not callable.'
				);
				$this->assertNotSame(
					'__return_true',
					$endpoint['permission_callback'],
					$route . ' is open to the world.'
				);
			}
		}
	}

	/**
	 * With no capabilities, every capability-gated callback must deny.
	 *
	 * This is the assertion with teeth. Shape tests confirm a callback exists;
	 * this confirms it does something. `current_user_can()` is stubbed to false
	 * globally, so a callback that still returns true is either reading a
	 * capability the stub does not model, or is not checking one at all.
	 *
	 * @dataProvider capabilityGatedControllers
	 */
	public function testPermissionChecksDenyWhenUserHasNoCapabilities( string $class ): void {
		global $wp_current_user_can;

		$wp_current_user_can = false;

		$controller = $this->makeController( $class );
		$checks     = $this->permissionMethodsOf( $controller );

		$this->assertNotEmpty(
			$checks,
			$class . ' exposes no *permissions_check method. If it registers routes, it needs one.'
		);

		foreach ( $checks as $method ) {
			$result = $controller->{$method}( new PermissionSurfaceRequestDouble() );

			$this->assertTrue(
				$result === false || $result instanceof WP_Error,
				$class . '::' . $method . '() granted access to a user with no capabilities. '
				. 'It must return false or WP_Error.'
			);
		}
	}

	/**
	 * A permissive WebMCP tool must declare itself public through its type.
	 *
	 * This is the test that protects the intentional public surface rather than
	 * fighting it. PublicTool exists so an anonymous visitor's in-browser agent
	 * can read published content it could already reach by browsing. That is a
	 * design decision with query guards behind it.
	 *
	 * What must not happen is a tool becoming permissive by accident — a
	 * `return true` in a tool that was never meant to be reachable anonymously.
	 * So the rule is structural: unconditional permission is legal only for a
	 * PublicTool subclass, and a PublicTool must be consistent about it.
	 */
	public function testPubliclyPermissiveWebMcpToolsDeclareThemselvesPublic(): void {
		$inconsistent = [];

		foreach ( $this->webMcpToolClasses() as $class ) {
			$reflection = new \ReflectionClass( $class );

			if ( $reflection->isAbstract() ) {
				continue;
			}

			$is_public_tool = $reflection->isSubclassOf( \Saltus\WP\Framework\WebMcp\Tools\PublicTool::class );
			$grants_all     = $this->grantsPermissionUnconditionally( $reflection );

			if ( $grants_all && ! $is_public_tool ) {
				$inconsistent[] = $class . ' grants permission unconditionally but does not extend PublicTool';
				continue;
			}

			if ( ! $is_public_tool ) {
				continue;
			}

			// A PublicTool that demanded a nonce would be requiring a session its
			// anonymous caller does not have — broken rather than merely strict.
			$instance = $reflection->newInstanceWithoutConstructor();

			if ( $instance->requires_authentication() !== false ) {
				$inconsistent[] = $class . ' extends PublicTool but requires authentication';
			}

			if ( $instance->get_discovery_capability() !== null ) {
				$inconsistent[] = $class . ' extends PublicTool but gates discovery on a capability';
			}
		}

		$this->assertSame(
			[],
			$inconsistent,
			"WebMCP's public surface must be declared, not incidental.\n"
			. "A tool reachable by an anonymous visitor belongs in PublicTool, whose\n"
			. 'query guards pin post_status to publish and filter meta through PublicFieldFilter.'
		);
	}

	/**
	 * WebMcpController's permissive-looking callback is backed by the handler.
	 *
	 * `execute_permissions_check()` gates on whether any model exposes WebMCP
	 * tools at all — deliberately not a capability, because the capability
	 * question differs per tool. That is only defensible while the handler
	 * actually enforces. This asserts the enforcement calls are still present,
	 * so the callback cannot become the only gate through a later refactor.
	 */
	public function testWebMcpExecuteEnforcementLivesInTheHandler(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Rest/WebMcpController.php'
		);

		$handler = $this->methodBody( $source, 'execute_tool' );

		$this->assertNotSame( '', $handler, 'execute_tool() not found in WebMcpController.' );

		$required = [
			'check_authentication' => 'login and nonce verification',
			'has_permission'       => "the tool's own capability check",
			'rate_limiter'         => 'rate limiting',
			'Validator::validate'  => 'argument re-validation against the tool schema',
		];

		foreach ( $required as $needle => $description ) {
			$this->assertStringContainsString(
				$needle,
				$handler,
				"execute_tool() no longer performs {$description}. Its permission_callback only\n"
				. "checks feature enablement, so removing this leaves the endpoint ungated."
			);
		}
	}

	/**
	 * PublicTool's guarantees are what justify its open permission check.
	 *
	 * Its docblock promises published-only, publicly-queryable-only, filtered
	 * meta. If those guards leave, the open permission check stops being safe,
	 * and the failure would be silent — hence asserting on them here.
	 */
	public function testPublicToolPinsQueriesToPublishedContent(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/WebMcp/Tools/PublicTool.php'
		);

		$this->assertStringContainsString(
			"'post_status'",
			$source,
			'PublicTool no longer constrains post_status, so unpublished content is reachable anonymously.'
		);
		$this->assertStringContainsString(
			"'publish'",
			$source,
			'PublicTool no longer pins post_status to publish.'
		);
		$this->assertStringContainsString(
			'PublicFieldFilter',
			$source,
			'PublicTool no longer filters meta through PublicFieldFilter, so private fields may leak.'
		);
	}

	/**
	 * Construct a controller with doubles for whatever it requires.
	 */
	private function makeController( string $class ): object {
		$modeler = $this->createStub( Modeler::class );

		switch ( $class ) {
			case AiAssistantController::class:
				// The providers and services below are final, so they are built
				// rather than doubled — the same approach the per-controller
				// tests take. Each accepts a null or stubbed Modeler.
				return new AiAssistantController(
					new ModelRestPolicy( $modeler ),
					new AiAssistantProvider( $modeler )
				);
			case AiContextController::class:
				return new AiContextController(
					new ModelRestPolicy( $modeler ),
					new AiContextProvider( $modeler )
				);
			case BlocksController::class:
				return new BlocksController( $modeler, new ModelRestPolicy( $modeler ) );
			case EditorialReviewController::class:
				return new EditorialReviewController( new ProposalService( new ProposalStore( null ) ) );
			case HealthController::class:
				return new HealthController( '2.0.0' );
			case MetaController::class:
				return new MetaController( $modeler );
			case ModelsController::class:
				return new ModelsController( $modeler );
			case RelationshipsController::class:
				return new RelationshipsController(
					$modeler,
					new ModelRestPolicy( $modeler ),
					new RelationshipManager( new RelationshipRegistry( $modeler ), new RelationshipStore( null ) )
				);
			default:
				// DuplicateController, ExportController, ReorderController and
				// SettingsController take only optional dependencies.
				return new $class();
		}
	}

	/**
	 * Permission-check method names on a controller.
	 *
	 * @return list<string>
	 */
	private function permissionMethodsOf( object $controller ): array {
		$methods = [];

		foreach ( ( new \ReflectionClass( $controller ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( substr( $method->getName(), -18 ) === 'permissions_check' || substr( $method->getName(), -17 ) === 'permissions_check' ) {
				$methods[] = $method->getName();
			}
		}

		return array_values( array_unique( $methods ) );
	}

	/**
	 * Normalize register_rest_route args into a list of endpoint arrays.
	 *
	 * A single endpoint is passed flat; multiple are passed as a numeric list.
	 *
	 * @param array<string|int, mixed> $args
	 * @return list<array<string, mixed>>
	 */
	private function endpointsOf( array $args ): array {
		if ( array_key_exists( 'permission_callback', $args ) || array_key_exists( 'callback', $args ) ) {
			return [ $args ];
		}

		$endpoints = [];

		foreach ( $args as $key => $value ) {
			if ( is_int( $key ) && is_array( $value ) ) {
				$endpoints[] = $value;
			}
		}

		return $endpoints;
	}

	/**
	 * Whether a tool's has_permission() returns true with no condition.
	 */
	private function grantsPermissionUnconditionally( \ReflectionClass $reflection ): bool {
		if ( ! $reflection->hasMethod( 'has_permission' ) ) {
			return false;
		}

		$method = $reflection->getMethod( 'has_permission' );
		$file   = $method->getFileName();

		if ( $file === false || $method->getStartLine() === false ) {
			return false;
		}

		$lines = array_slice(
			(array) file( $file ),
			$method->getStartLine() - 1,
			$method->getEndLine() - $method->getStartLine() + 1
		);

		$body = preg_replace( '#//.*$|/\*.*?\*/#s', '', implode( '', $lines ) );

		return (bool) preg_match( '/\breturn\s+true\s*;/', (string) $body );
	}

	/**
	 * Extract a method body from PHP source by brace matching.
	 */
	private function methodBody( string $source, string $method ): string {
		$position = strpos( $source, 'function ' . $method . '(' );

		if ( $position === false ) {
			return '';
		}

		$opening = strpos( $source, '{', $position );

		if ( $opening === false ) {
			return '';
		}

		$depth  = 0;
		$length = strlen( $source );

		for ( $index = $opening; $index < $length; $index++ ) {
			if ( $source[ $index ] === '{' ) {
				$depth++;
			} elseif ( $source[ $index ] === '}' ) {
				$depth--;

				if ( $depth === 0 ) {
					return substr( $source, $opening, $index - $opening + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * Concrete WebMCP tool class names.
	 *
	 * @return list<class-string>
	 */
	private function webMcpToolClasses(): array {
		$classes = [];
		$dir     = dirname( __DIR__, 2 ) . '/src/WebMcp/Tools';

		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			$class = 'Saltus\\WP\\Framework\\WebMcp\\Tools\\' . basename( (string) $file, '.php' );

			if ( class_exists( $class ) ) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	/**
	 * Every PHP file under src/.
	 *
	 * @return list<string>
	 */
	private function sourceFiles(): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	private function relativePath( string $path ): string {
		return str_replace( dirname( __DIR__, 2 ) . '/', '', $path );
	}
}

/**
 * A request that answers every get_param() with null.
 *
 * Permission checks must deny an unauthenticated caller regardless of what the
 * request carries, so the emptiest possible request is the right probe.
 */
class PermissionSurfaceRequestDouble {

	/**
	 * @return null
	 */
	public function get_param( string $key ) {
		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_params(): array {
		return [];
	}

	/**
	 * @return null
	 */
	public function get_header( string $key ) {
		return null;
	}
}
