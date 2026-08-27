<?php

namespace Saltus\WP\Framework\Tests\MCP\Abilities;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Abilities\AbilityDefinitionFactory;
use Saltus\WP\Framework\MCP\Tools\DeletePost;
use Saltus\WP\Framework\MCP\Tools\GetHealth;
use Saltus\WP\Framework\MCP\Tools\GetPost;
use Saltus\WP\Framework\MCP\Tools\ListModels;
use Saltus\WP\Framework\MCP\Tools\UpdatePost;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Abilities\AbilityDefinitionFactory
 * @covers \Saltus\WP\Framework\MCP\Validation\ParameterSchema
 */
class AbilityDefinitionFactoryTest extends TestCase {

	private function factory(): AbilityDefinitionFactory {
		return new AbilityDefinitionFactory();
	}

	private function modeler(): Modeler {
		return new Modeler( $this->createStub( ModelFactory::class ) );
	}

	public function testPublishedSchemaIsAJsonSchemaObjectRatherThanAParameterMap(): void {
		$definition = $this->factory()->from_tool( new GetPost( $this->modeler() ) );
		$schema     = $definition['input_schema'];

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'post_id', $schema['properties'] );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
	}

	public function testEveryDeclaredParameterSurvivesPublication(): void {
		$tool       = new GetPost( $this->modeler() );
		$definition = $this->factory()->from_tool( $tool );

		$this->assertSame(
			array_keys( $tool->get_parameters() ),
			array_keys( $definition['input_schema']['properties'] )
		);
	}

	public function testRequiredIsLiftedOutOfPropertiesIntoASiblingList(): void {
		$schema = $this->factory()->from_tool( new GetPost( $this->modeler() ) )['input_schema'];

		$this->assertSame( [ 'post_id' ], $schema['required'] );
		$this->assertArrayNotHasKey( 'required', $schema['properties']['post_id'] );
	}

	/**
	 * A parameter named after a schema keyword must not land in that keyword's slot.
	 *
	 * `list_models` declares a `type` parameter. Published unwrapped, its
	 * definition became the schema's own `type`, which core reads as a
	 * multi-type declaration and fatals on while validating any input.
	 */
	public function testAParameterNamedAfterAKeywordDoesNotBecomeThatKeyword(): void {
		$schema = $this->factory()->from_tool( new ListModels( $this->modeler() ) )['input_schema'];

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'type', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['type']['type'] );
	}

	public function testAParameterlessToolPublishesAnObjectWithNoProperties(): void {
		$schema = $this->factory()->from_tool( new GetHealth( $this->modeler() ) )['input_schema'];

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( [], $schema['properties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	/**
	 * An argument-free call has to survive core's input validation.
	 *
	 * `WP_Ability::normalize_input()` only substitutes the schema's top-level
	 * `default` when the caller sent nothing. Without one the input stays null,
	 * fails the object type check, and the ability answers 400 even when it
	 * takes no arguments.
	 */
	public function testEverySchemaCarriesADefaultForAnArgumentFreeCall(): void {
		foreach ( [ new GetHealth( $this->modeler() ), new GetPost( $this->modeler() ) ] as $tool ) {
			$schema = $this->factory()->from_tool( $tool )['input_schema'];

			$this->assertArrayHasKey( 'default', $schema, $tool->get_name() . ' must normalize an absent input.' );
			$this->assertSame( [], $schema['default'] );
		}
	}

	/**
	 * Only keys core recognises may be published.
	 *
	 * `WP_Ability::prepare_properties()` hands the whole argument array to the
	 * constructor, which calls `_doing_it_wrong()` for every key that is not a
	 * declared property. A camelCase alias or a second callback key is not a
	 * harmless duplicate: it is a notice on every registration.
	 */
	public function testTheDefinitionPublishesOnlyPropertiesCoreDeclares(): void {
		$definition = $this->factory()->from_tool( new GetPost( $this->modeler() ) );

		$this->assertSame(
			[ 'name', 'label', 'description', 'category', 'input_schema', 'execute_callback', 'permission_callback', 'meta' ],
			array_keys( $definition )
		);
	}

	public function testExposureIntentIsDeclaredOnBothChannelKeys(): void {
		$meta = $this->factory()->from_tool( new GetPost( $this->modeler() ) )['meta'];

		$this->assertTrue( $meta['public'], 'Tools built for clients must declare the intent flag.' );
		$this->assertTrue( $meta['show_in_rest'], 'REST exposure must stay explicit, not inherited.' );
	}

	/**
	 * Write tools declare the same intent as read tools.
	 *
	 * Exposure is decided before the definition exists: a tool the policy
	 * disables is never registered, and `permission_callback` gates each call.
	 * Withholding the flag from write tools would only hide them from clients
	 * that filter on intent, without adding a capability check.
	 */
	public function testWriteToolsDeclareTheSameIntentAsReadTools(): void {
		$read  = $this->factory()->from_tool( new GetPost( $this->modeler() ) )['meta'];
		$write = $this->factory()->from_tool( new DeletePost( $this->modeler() ) )['meta'];

		$this->assertSame( $read['public'], $write['public'] );
	}

	/**
	 * A read is published as readable, which is what picks its HTTP verb.
	 *
	 * `WP_REST_Abilities_V1_Run_Controller::validate_request_method()` answers
	 * 405 unless the verb matches the annotations, and an unstated `readonly`
	 * means POST. Left null, every read had to be posted to and no client could
	 * find it by filtering the collection on `annotations[readonly]`.
	 */
	public function testAReadIsAnnotatedReadOnly(): void {
		$annotations = $this->factory()->from_tool( new GetPost( $this->modeler() ) )['meta']['annotations'];

		$this->assertTrue( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
	}

	public function testAWriteIsNotAnnotatedReadOnly(): void {
		$annotations = $this->factory()->from_tool( new DeletePost( $this->modeler() ) )['meta']['annotations'];

		$this->assertFalse( $annotations['readonly'], 'A write must never be advertised as readable.' );
		$this->assertTrue( $annotations['destructive'], 'Deleting a post removes what was there.' );
	}

	/**
	 * No write claims idempotence, because the claim costs a usable payload.
	 *
	 * Core reads `destructive` and `idempotent` together as a request for the
	 * DELETE verb, and its run route takes a DELETE payload from the query
	 * string rather than the body. Claiming it for `update_post` would push a
	 * post's content into a URL.
	 */
	public function testNoWriteClaimsIdempotence(): void {
		foreach ( [ new DeletePost( $this->modeler() ), new UpdatePost( $this->modeler() ) ] as $tool ) {
			$annotations = $this->factory()->from_tool( $tool )['meta']['annotations'];

			$this->assertNull( $annotations['idempotent'], $tool->get_name() . ' must not ask for the DELETE verb.' );
		}
	}
}
