<?php

namespace Saltus\WP\Framework\Tests\Unit\Models;

use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\PostType;
use Saltus\WP\Framework\Tests\TestCase;

/**
 * @covers \Saltus\WP\Framework\Models\BaseModel
 * @covers \Saltus\WP\Framework\Models\Config\NoFile
 * @covers \Saltus\WP\Framework\Models\PostType
 */
class BaseModelTest extends TestCase {
	public function testGetNameReturnsNameFromConfig(): void {
		$model = new PostType( new NoFile( [ 'name' => 'movie', 'type' => 'post_type' ] ) );

		$this->assertSame( 'movie', $model->get_name() );
	}
}
