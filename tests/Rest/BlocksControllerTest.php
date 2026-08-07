<?php
namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\BlocksController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use WP_Error;
use WP_REST_Request;

require_once __DIR__ . '/functions.php';

/** @covers \Saltus\WP\Framework\Rest\BlocksController */
class BlocksControllerTest extends TestCase {

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
	}

	public function testRegistersRouteAndChecksPermission(): void {
		global $wp_rest_routes_registered, $wp_current_user_can;
		$modeler    = $this->modeler( [] );
		$controller = new BlocksController( $modeler, new ModelRestPolicy( $modeler ) );
		$controller->register_routes();
		$this->assertSame( '/blocks', $wp_rest_routes_registered[0]['route'] );

		$wp_current_user_can = false;
		$this->assertInstanceOf( WP_Error::class, $controller->get_items_permissions_check( new WP_REST_Request() ) );
	}

	public function testReturnsOnlyRestEnabledBlockModels(): void {
		$visible = $this->model( 'book', true );
		$hidden  = $this->model( 'note', false );
		$modeler = $this->modeler( [ $visible, $hidden ] );
		$result  = ( new BlocksController( $modeler, new ModelRestPolicy( $modeler ) ) )->get_items( new WP_REST_Request() )->get_data();

		$this->assertCount( 1, $result['post_types'] );
		$this->assertSame( 'book', $result['post_types'][0]['post_type'] );
		$this->assertSame( 'saltus/book-list', $result['post_types'][0]['blocks']['list'] );
	}

	/** @param list<Model> $models */
	private function modeler( array $models ): Modeler {
		$modeler = $this->createStub( Modeler::class );
		$map     = [];
		foreach ( $models as $model ) {
			$map[ $model->get_name() ] = $model;
		}
		$modeler->method( 'get_models' )->willReturn( $map );
		return $modeler;
	}

	private function model( string $name, bool $show_in_rest ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( [ 'blocks' => [ 'list' => true, 'show_in_rest' => $show_in_rest ] ] );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'labels' => [ 'name' => ucfirst( $name ) . 's', 'singular_name' => ucfirst( $name ) ] ] );
		return $model;
	}
}
