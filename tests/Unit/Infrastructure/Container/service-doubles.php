<?php
/**
 * Service doubles for ServiceContainerTest.
 *
 * These are real classes rather than PHPUnit mocks because the container
 * resolves them by class-string through reflection, which a mock's generated
 * name cannot satisfy for the Conditional static call.
 */

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Container\Doubles;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Actionable;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Infrastructure\Services\Assets\HasAssets;

/** The simplest possible service: no interfaces, no side effects. */
class PlainService implements Service {
}

/** Records the dependency array the container passed in. */
class NeedsDependencies implements Service {

	/** @var array<string, mixed> */
	public array $dependencies;

	/** @param array<string, mixed> $dependencies */
	public function __construct( array $dependencies = [] ) {
		$this->dependencies = $dependencies;
	}
}

/** Counts register() and add_action() calls so double-wiring is detectable. */
class CountingService implements Service, Registerable, Actionable {

	public static int $registered  = 0;
	public static int $actions_run = 0;

	public function register() {
		++self::$registered;
	}

	public function add_action(): void {
		++self::$actions_run;
	}
}

/** Declares its own hook and priority. */
class CustomHookService implements Service, Actionable {

	public function add_action(): void {
	}

	public function filter(): string {
		return 'admin_init';
	}

	public function priority(): int {
		return 25;
	}
}

/** Conditional service that reports it is not needed. */
class UnneededService implements Service, Conditional {

	public static int $built = 0;

	public function __construct() {
		++self::$built;
	}

	public static function is_needed(): bool {
		return false;
	}
}

/** Conditional service that reports it is needed. */
class NeededService implements Service, Conditional {

	public static function is_needed(): bool {
		return true;
	}
}

/** Carries assets, so the container must wire both enqueue hooks. */
class AssetService implements Service, HasAssets {

	public static int $lists_set = 0;

	public function set_assets_list(): void {
		++self::$lists_set;
	}

	public function register_assets(): void {
	}
}

/** Not a Service, so the container must refuse it. */
class NotAService {
}

/** Abstract, so reflection must report it as not instantiable. */
abstract class AbstractService implements Service {
}
