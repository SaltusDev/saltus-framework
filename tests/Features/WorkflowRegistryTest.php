<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Workflow\InvalidWorkflow;
use Saltus\WP\Framework\Features\Workflow\WorkflowRegistry;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\PostType;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\Features\Workflow\WorkflowRegistry */
/** @covers \Saltus\WP\Framework\Features\Workflow\WorkflowDefinition */
/** @covers \Saltus\WP\Framework\Features\Workflow\WorkflowState */
/** @covers \Saltus\WP\Framework\Features\Workflow\WorkflowTransition */
class WorkflowRegistryTest extends TestCase {

	/** @return array<string, mixed> */
	private function config(): array {
		return [
			'enabled'     => true,
			'states'      => [
				[ 'slug' => 'draft', 'label' => 'Draft', 'public' => false ],
				[ 'slug' => 'in_legal_review', 'label' => 'In Legal Review', 'public' => false, 'notification' => 'legal@company.com' ],
				[ 'slug' => 'approved', 'label' => 'Approved', 'public' => false ],
				[ 'slug' => 'published', 'label' => 'Published', 'public' => true ],
				[ 'slug' => 'archived', 'label' => 'Archived', 'public' => false ],
			],
			'transitions' => [
				'submit_for_review' => [ 'from' => [ 'draft' ], 'to' => 'in_legal_review', 'capability' => 'edit_posts', 'label' => 'Submit for Review' ],
				'approve'           => [ 'from' => [ 'in_legal_review' ], 'to' => 'approved', 'capability' => 'publish_posts', 'label' => 'Approve' ],
				'publish'           => [ 'from' => [ 'approved' ], 'to' => 'published', 'capability' => 'publish_posts', 'label' => 'Publish', 'action_hook' => true ],
				'archive'           => [ 'from' => [ 'published' ], 'to' => 'archived', 'capability' => 'edit_posts', 'label' => 'Archive' ],
			],
		];
	}

	private function registry(): WorkflowRegistry {
		$registry = new WorkflowRegistry();
		$registry->register( 'movie', $this->config() );

		return $registry;
	}

	public function testRegistersStatesInDeclarationOrder(): void {
		$definition = $this->registry()->require( 'movie' );

		$this->assertSame(
			[ 'draft', 'in_legal_review', 'approved', 'published', 'archived' ],
			array_keys( $definition->states )
		);
	}

	public function testInitialStateIsTheFirstDeclared(): void {
		$this->assertSame( 'draft', $this->registry()->require( 'movie' )->initial_state() );
	}

	public function testStateCarriesLabelAndVisibility(): void {
		$definition = $this->registry()->require( 'movie' );

		$this->assertSame( 'In Legal Review', $definition->state( 'in_legal_review' )->label );
		$this->assertFalse( $definition->state( 'in_legal_review' )->is_public );
		$this->assertTrue( $definition->state( 'published' )->is_public );
	}

	public function testPublicStatesAreIdentified(): void {
		$this->assertSame( [ 'published' ], $this->registry()->require( 'movie' )->public_states() );
	}

	public function testNotificationIsCarriedOnTheState(): void {
		$definition = $this->registry()->require( 'movie' );

		$this->assertTrue( $definition->state( 'in_legal_review' )->notifies() );
		$this->assertSame( 'legal@company.com', $definition->state( 'in_legal_review' )->notification );
		$this->assertFalse( $definition->state( 'draft' )->notifies() );
	}

	public function testTransitionsAreRegisteredWithCapabilities(): void {
		$definition = $this->registry()->require( 'movie' );

		$this->assertSame( 'publish_posts', $definition->transition( 'approve' )->capability );
		$this->assertSame( 'Approve', $definition->transition( 'approve' )->label() );
		$this->assertSame( 'approved', $definition->transition( 'approve' )->to );
	}

	public function testTransitionCapabilityDefaultsToEditPosts(): void {
		$registry = new WorkflowRegistry();
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ], [ 'slug' => 'done' ] ],
				'transitions' => [ 'finish' => [ 'from' => 'draft', 'to' => 'done' ] ],
			]
		);

		$this->assertSame( 'edit_posts', $registry->require( 'movie' )->transition( 'finish' )->capability );
	}

	public function testActionHookFlagIsCarried(): void {
		$definition = $this->registry()->require( 'movie' );

		$this->assertTrue( $definition->transition( 'publish' )->action_hook );
		$this->assertFalse( $definition->transition( 'approve' )->action_hook );
	}

	public function testTransitionsFromReturnsOnlyLegalMoves(): void {
		$definition = $this->registry()->require( 'movie' );

		$this->assertSame( [ 'submit_for_review' ], array_keys( $definition->transitions_from( 'draft' ) ) );
		$this->assertSame( [ 'approve' ], array_keys( $definition->transitions_from( 'in_legal_review' ) ) );
		$this->assertSame( [], array_keys( $definition->transitions_from( 'archived' ) ) );
	}

	public function testAllowsFromChecksSourceStates(): void {
		$transition = $this->registry()->require( 'movie' )->transition( 'submit_for_review' );

		$this->assertTrue( $transition->allows_from( 'draft' ) );
		$this->assertFalse( $transition->allows_from( 'published' ) );
	}

	public function testMapFormStatesAreSupported(): void {
		$registry = new WorkflowRegistry();
		$registry->register(
			'movie',
			[
				'states'      => [
					'draft'     => [ 'label' => 'Draft' ],
					'published' => [ 'label' => 'Published', 'public' => true ],
				],
				'transitions' => [ 'publish' => [ 'from' => 'draft', 'to' => 'published' ] ],
			]
		);

		$definition = $registry->require( 'movie' );
		$this->assertSame( [ 'draft', 'published' ], array_keys( $definition->states ) );
		$this->assertTrue( $definition->state( 'published' )->is_public );
	}

	public function testBareStringStatesAreSupportedAndLabelled(): void {
		$registry = new WorkflowRegistry();
		$registry->register(
			'movie',
			[
				'states'      => [ 'draft', 'in_legal_review' ],
				'transitions' => [ 'submit' => [ 'from' => 'draft', 'to' => 'in_legal_review' ] ],
			]
		);

		$definition = $registry->require( 'movie' );
		$this->assertSame( [ 'draft', 'in_legal_review' ], array_keys( $definition->states ) );
		$this->assertSame( 'In legal review', $definition->state( 'in_legal_review' )->label );
	}

	public function testScalarFromIsAcceptedAsASingleState(): void {
		$registry = new WorkflowRegistry();
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ], [ 'slug' => 'done' ] ],
				'transitions' => [ 'finish' => [ 'from' => 'draft', 'to' => 'done' ] ],
			]
		);

		$this->assertSame( [ 'draft' ], $registry->require( 'movie' )->transition( 'finish' )->from );
	}

	public function testMissingStatesThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'declares no states' );
		$registry->register( 'movie', [ 'transitions' => [] ] );
	}

	public function testDuplicateStateThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'more than once' );
		$registry->register( 'movie', [ 'states' => [ [ 'slug' => 'draft' ], [ 'slug' => 'draft' ] ] ] );
	}

	public function testStateWithoutSlugThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'no slug' );
		$registry->register( 'movie', [ 'states' => [ [ 'label' => 'Nameless' ] ] ] );
	}

	public function testTransitionToUnknownStateThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'undeclared state "nowhere"' );
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ] ],
				'transitions' => [ 'go' => [ 'from' => 'draft', 'to' => 'nowhere' ] ],
			]
		);
	}

	public function testTransitionFromUnknownStateThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'undeclared state "nowhere"' );
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ] ],
				'transitions' => [ 'go' => [ 'from' => 'nowhere', 'to' => 'draft' ] ],
			]
		);
	}

	public function testTransitionWithoutTargetThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'missing the required "to" state' );
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ] ],
				'transitions' => [ 'go' => [ 'from' => 'draft' ] ],
			]
		);
	}

	public function testTransitionWithoutSourceThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'declares no "from" states' );
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ], [ 'slug' => 'done' ] ],
				'transitions' => [ 'go' => [ 'to' => 'done', 'from' => [] ] ],
			]
		);
	}

	public function testMalformedTransitionThrows(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'must be an array of settings' );
		$registry->register(
			'movie',
			[
				'states'      => [ [ 'slug' => 'draft' ] ],
				'transitions' => [ 'go' => 'draft' ],
			]
		);
	}

	public function testRequireThrowsForModelWithoutWorkflow(): void {
		$registry = new WorkflowRegistry();

		$this->expectException( InvalidWorkflow::class );
		$this->expectExceptionMessage( 'has no workflow' );
		$registry->require( 'movie' );
	}

	public function testModelConfigIsReadFromTheModel(): void {
		$registry = new WorkflowRegistry();
		$model    = new PostType( new NoFile( [ 'name' => 'movie', 'workflow' => $this->config() ] ) );

		$definition = $registry->register_from_model( $model );

		$this->assertNotNull( $definition );
		$this->assertTrue( $registry->has( 'movie' ) );
		$this->assertSame( 'draft', $definition->initial_state() );
	}

	public function testModelWithoutWorkflowRegistersNothing(): void {
		$registry = new WorkflowRegistry();

		$this->assertNull( $registry->register_from_model( new PostType( new NoFile( [ 'name' => 'movie' ] ) ) ) );
		$this->assertFalse( $registry->has( 'movie' ) );
	}

	public function testDisabledWorkflowIsSkippedRatherThanRegistered(): void {
		$registry = new WorkflowRegistry();
		$config   = $this->config();
		$config['enabled'] = false;

		$this->assertNull( $registry->register_from_model( new PostType( new NoFile( [ 'name' => 'movie', 'workflow' => $config ] ) ) ) );
		$this->assertFalse( $registry->has( 'movie' ) );
	}

	public function testDefinitionSerializesForPayloads(): void {
		$data = $this->registry()->require( 'movie' )->to_array();

		$this->assertSame( 'movie', $data['model'] );
		$this->assertSame( 'draft', $data['initial_state'] );
		$this->assertCount( 5, $data['states'] );
		$this->assertCount( 4, $data['transitions'] );
		$this->assertSame( 'Approve', $data['transitions']['approve']['label'] );
	}

	public function testModelsAndAllExposeRegisteredState(): void {
		$registry = $this->registry();

		$this->assertSame( [ 'movie' ], $registry->models() );
		$this->assertCount( 1, $registry->all() );
		$this->assertNull( $registry->get( 'unknown' ) );
	}
}
