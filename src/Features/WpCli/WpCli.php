<?php
namespace Saltus\WP\Framework\Features\WpCli;

use Saltus\WP\Framework\Features\WpCli\Commands\BlockCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\MetaCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\ModelCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\PostCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\ReorderCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\SaltusCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\SettingsCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\TermCommand;
use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Service;

/** Registers the wp saltus command tree. @api */
final class WpCli implements Service, Conditional, Registerable {
	/** @var callable */
	private $modeler_resolver;
	private CliGateway $cli;

	/** @param array<string, mixed> $dependencies Framework dependencies. */
	public function __construct( array $dependencies = [], ?CliGateway $cli = null ) {
		$resolver               = $dependencies['modeler_resolver'] ?? null;
		$this->modeler_resolver = is_callable( $resolver ) ? $resolver : static function () {
			return null;
		};
		$this->cli              = $cli ?? new WordPressCliGateway();
	}

	public static function is_needed(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	public function register(): void {
		add_action( 'cli_init', [ $this, 'register_commands' ] );
	}

	public function register_commands(): void {
		$resolver = $this->modeler_resolver;
		$this->cli->add_command( 'saltus', new SaltusCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus model', new ModelCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus post', new PostCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus term', new TermCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus settings', new SettingsCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus meta', new MetaCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus reorder', new ReorderCommand( $this->cli, $resolver ) );
		$this->cli->add_command( 'saltus block', new BlockCommand( $this->cli, $resolver ) );
	}
}
