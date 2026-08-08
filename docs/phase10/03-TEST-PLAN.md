# Phase 10A: Content Relationships - Test Plan

**Version:** 1.0  
**Date:** 2026-08-08  
**Owner:** QA Engineer  
**Status:** Draft for Review

---

## Test Strategy

### Testing Pyramid

```
           /\
          /  \         5% - E2E Browser Tests
         /____\        
        /      \       15% - Integration Tests
       /________\      
      /          \     80% - Unit Tests
     /____________\    
```

**Target Coverage:** 85% overall, 95% for core relationship logic

### Test Types

1. **Unit Tests** - Individual class methods, pure functions
2. **Integration Tests** - Database operations, WordPress integration
3. **API Tests** - REST endpoints, MCP tools
4. **Browser Tests** - Admin UI, metabox interactions
5. **Performance Tests** - Query optimization, large datasets

---

## Unit Tests

### RelationshipRegistry Tests

**File:** `tests/Unit/Features/Relationships/RelationshipRegistryTest.php`

```php
<?php
namespace Saltus\WP\Framework\Tests\Unit\Features\Relationships;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\Exceptions\InvalidRelationshipException;

class RelationshipRegistryTest extends TestCase {
    
    private RelationshipRegistry $registry;
    
    protected function setUp(): void {
        $this->registry = new RelationshipRegistry();
    }
    
    public function test_register_valid_has_one_relationship(): void {
        $this->registry->register('movie', 'director', [
            'type' => 'has_one',
            'model' => 'person',
        ]);
        
        $config = $this->registry->get('movie', 'director');
        
        $this->assertNotNull($config);
        $this->assertEquals('has_one', $config['type']);
        $this->assertEquals('person', $config['to_post_type']);
    }
    
    public function test_register_invalid_type_throws_exception(): void {
        $this->expectException(InvalidRelationshipException::class);
        $this->expectExceptionMessage('Invalid relationship type');
        
        $this->registry->register('movie', 'bad', [
            'type' => 'invalid_type',
            'model' => 'person',
        ]);
    }
    
    public function test_register_missing_model_throws_exception(): void {
        $this->expectException(InvalidRelationshipException::class);
        $this->expectExceptionMessage('Related model is required');
        
        $this->registry->register('movie', 'director', [
            'type' => 'has_one',
        ]);
    }
    
    public function test_has_relationships_returns_true_when_exists(): void {
        $this->registry->register('movie', 'director', [
            'type' => 'has_one',
            'model' => 'person',
        ]);
        
        $this->assertTrue($this->registry->has_relationships('movie'));
        $this->assertFalse($this->registry->has_relationships('book'));
    }
    
    public function test_get_all_returns_all_registered_relationships(): void {
        $this->registry->register('movie', 'director', [
            'type' => 'has_one',
            'model' => 'person',
        ]);
        
        $this->registry->register('movie', 'actors', [
            'type' => 'has_many',
            'model' => 'person',
        ]);
        
        $all = $this->registry->get_all();
        
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('movie_director', $all);
        $this->assertArrayHasKey('movie_actors', $all);
    }
}
```

### RelationshipManager Tests

**File:** `tests/Unit/Features/Relationships/RelationshipManagerTest.php`

```php
<?php
namespace Saltus\WP\Framework\Tests\Unit\Features\Relationships;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;

class RelationshipManagerTest extends TestCase {
    
    private RelationshipManager $manager;
    private RelationshipRegistry $registry;
    
    protected function setUp(): void {
        $this->registry = $this->createMock(RelationshipRegistry::class);
        $this->manager = new RelationshipManager($this->registry);
    }
    
    public function test_create_relationship_returns_id(): void {
        // Mock WordPress globals
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 789;
        
        $wpdb->expects($this->once())
            ->method('insert')
            ->willReturn(1);
        
        $id = $this->manager->create('movie_actors', 123, 456, []);
        
        $this->assertEquals(789, $id);
    }
    
    public function test_exists_returns_true_when_relationship_exists(): void {
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        
        $wpdb->expects($this->once())
            ->method('get_var')
            ->willReturn(1);
        
        $exists = $this->manager->exists('movie_actors', 123, 456);
        
        $this->assertTrue($exists);
    }
    
    public function test_get_related_ids_returns_array_of_integers(): void {
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        
        $wpdb->expects($this->once())
            ->method('get_col')
            ->willReturn(['456', '789']);
        
        $ids = $this->manager->get_related_ids('movie_actors', 123);
        
        $this->assertIsArray($ids);
        $this->assertCount(2, $ids);
        $this->assertSame(456, $ids[0]);
        $this->assertSame(789, $ids[1]);
    }
}
```

---

## Integration Tests

### Database Integration Tests

**File:** `tests/Integration/Features/Relationships/DatabaseTest.php`

```php
<?php
namespace Saltus\WP\Framework\Tests\Integration\Features\Relationships;

use WP_UnitTestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;

class DatabaseTest extends WP_UnitTestCase {
    
    private RelationshipManager $manager;
    private int $movie_id;
    private int $actor1_id;
    private int $actor2_id;
    
    public function setUp(): void {
        parent::setUp();
        
        // Create test posts
        $this->movie_id = $this->factory->post->create([
            'post_type' => 'movie',
            'post_title' => 'Test Movie',
        ]);
        
        $this->actor1_id = $this->factory->post->create([
            'post_type' => 'person',
            'post_title' => 'Actor One',
        ]);
        
        $this->actor2_id = $this->factory->post->create([
            'post_type' => 'person',
            'post_title' => 'Actor Two',
        ]);
        
        $registry = new RelationshipRegistry();
        $this->manager = new RelationshipManager($registry);
    }
    
    public function test_create_and_retrieve_relationship(): void {
        // Create relationship
        $id = $this->manager->create(
            'movie_actors',
            $this->movie_id,
            $this->actor1_id,
            ['role' => 'Hero']
        );
        
        $this->assertGreaterThan(0, $id);
        
        // Retrieve
        $related_ids = $this->manager->get_related_ids('movie_actors', $this->movie_id);
        
        $this->assertContains($this->actor1_id, $related_ids);
    }
    
    public function test_delete_relationship(): void {
        // Create
        $this->manager->create('movie_actors', $this->movie_id, $this->actor1_id);
        
        // Verify exists
        $this->assertTrue($this->manager->exists('movie_actors', $this->movie_id, $this->actor1_id));
        
        // Delete
        $result = $this->manager->delete('movie_actors', $this->movie_id, $this->actor1_id);
        
        $this->assertTrue($result);
        $this->assertFalse($this->manager->exists('movie_actors', $this->movie_id, $this->actor1_id));
    }
    
    public function test_sync_relationships(): void {
        // Create initial relationships
        $this->manager->create('movie_actors', $this->movie_id, $this->actor1_id);
        
        // Sync to new set
        $this->manager->sync('movie_actors', $this->movie_id, [$this->actor2_id]);
        
        // Verify
        $related_ids = $this->manager->get_related_ids('movie_actors', $this->movie_id);
        
        $this->assertCount(1, $related_ids);
        $this->assertContains($this->actor2_id, $related_ids);
        $this->assertNotContains($this->actor1_id, $related_ids);
    }
    
    public function test_cascade_delete_removes_relationships(): void {
        // Create relationship
        $this->manager->create('movie_actors', $this->movie_id, $this->actor1_id);
        
        // Delete post (triggers cascade)
        wp_delete_post($this->movie_id, true);
        
        // Verify relationships removed
        $related_ids = $this->manager->get_related_ids('movie_actors', $this->movie_id);
        
        $this->assertEmpty($related_ids);
    }
    
    public function test_order_index_maintains_order(): void {
        // Create multiple in order
        $this->manager->create('movie_actors', $this->movie_id, $this->actor1_id);
        $this->manager->create('movie_actors', $this->movie_id, $this->actor2_id);
        
        // Retrieve
        $related_ids = $this->manager->get_related_ids('movie_actors', $this->movie_id);
        
        // Should maintain insertion order
        $this->assertEquals([$this->actor1_id, $this->actor2_id], $related_ids);
    }
}
```

---

## REST API Tests

**File:** `tests/Integration/Rest/RelationshipsControllerTest.php`

```php
<?php
namespace Saltus\WP\Framework\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;

class RelationshipsControllerTest extends WP_UnitTestCase {
    
    private int $admin_id;
    private int $movie_id;
    private int $actor_id;
    
    public function setUp(): void {
        parent::setUp();
        
        // Create admin user
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        
        // Create test posts
        $this->movie_id = $this->factory->post->create(['post_type' => 'movie']);
        $this->actor_id = $this->factory->post->create(['post_type' => 'person']);
    }
    
    public function test_get_relationships_for_post_type(): void {
        wp_set_current_user($this->admin_id);
        
        $request = new WP_REST_Request('GET', '/saltus-framework/v1/relationships/movie');
        $response = rest_do_request($request);
        
        $this->assertEquals(200, $response->get_status());
        
        $data = $response->get_data();
        $this->assertArrayHasKey('relationships', $data);
        $this->assertIsArray($data['relationships']);
    }
    
    public function test_get_related_posts(): void {
        wp_set_current_user($this->admin_id);
        
        // Create relationship first
        Relations::create('movie', $this->movie_id, 'actors', $this->actor_id);
        
        $request = new WP_REST_Request(
            'GET',
            "/saltus-framework/v1/posts/{$this->movie_id}/relationships/actors"
        );
        $response = rest_do_request($request);
        
        $this->assertEquals(200, $response->get_status());
        
        $data = $response->get_data();
        $this->assertArrayHasKey('related_posts', $data);
        $this->assertCount(1, $data['related_posts']);
        $this->assertEquals($this->actor_id, $data['related_posts'][0]['id']);
    }
    
    public function test_create_relationship_via_rest(): void {
        wp_set_current_user($this->admin_id);
        
        $request = new WP_REST_Request(
            'POST',
            "/saltus-framework/v1/posts/{$this->movie_id}/relationships/actors"
        );
        $request->set_body_params([
            'related_id' => $this->actor_id,
            'pivot_data' => ['role' => 'Hero'],
        ]);
        
        $response = rest_do_request($request);
        
        $this->assertEquals(201, $response->get_status());
        
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertGreaterThan(0, $data['relationship_id']);
    }
    
    public function test_create_relationship_requires_permission(): void {
        // Not logged in
        wp_set_current_user(0);
        
        $request = new WP_REST_Request(
            'POST',
            "/saltus-framework/v1/posts/{$this->movie_id}/relationships/actors"
        );
        $request->set_body_params(['related_id' => $this->actor_id]);
        
        $response = rest_do_request($request);
        
        $this->assertEquals(401, $response->get_status());
    }
    
    public function test_delete_relationship_via_rest(): void {
        wp_set_current_user($this->admin_id);
        
        // Create first
        Relations::create('movie', $this->movie_id, 'actors', $this->actor_id);
        
        // Delete
        $request = new WP_REST_Request(
            'DELETE',
            "/saltus-framework/v1/posts/{$this->movie_id}/relationships/actors/{$this->actor_id}"
        );
        
        $response = rest_do_request($request);
        
        $this->assertEquals(200, $response->get_status());
        
        $data = $response->get_data();
        $this->assertTrue($data['success']);
    }
}
```

---

## Performance Tests

**File:** `tests/Performance/RelationshipQueryTest.php`

```php
<?php
namespace Saltus\WP\Framework\Tests\Performance;

use WP_UnitTestCase;
use Saltus\WP\Framework\Features\Relationships\Relations;

class RelationshipQueryTest extends WP_UnitTestCase {
    
    public function test_eager_loading_prevents_n_plus_one(): void {
        // Create 10 movies with 5 actors each
        $movie_ids = [];
        $actor_ids = [];
        
        for ($i = 0; $i < 10; $i++) {
            $movie_ids[] = $this->factory->post->create(['post_type' => 'movie']);
        }
        
        for ($i = 0; $i < 5; $i++) {
            $actor_ids[] = $this->factory->post->create(['post_type' => 'person']);
        }
        
        foreach ($movie_ids as $movie_id) {
            foreach ($actor_ids as $actor_id) {
                Relations::create('movie', $movie_id, 'actors', $actor_id);
            }
        }
        
        // Reset query counter
        global $wpdb;
        $wpdb->num_queries = 0;
        
        // Eager load
        $movies = Relations::for('movie')
            ->with('actors')
            ->get(['posts_per_page' => 10]);
        
        $eager_queries = $wpdb->num_queries;
        
        // Should be 2-3 queries: 1 for movies, 1 for relationships, 1 for actors
        $this->assertLessThanOrEqual(3, $eager_queries);
        
        // Reset for lazy loading test
        wp_cache_flush();
        $wpdb->num_queries = 0;
        
        // Lazy load (N+1 problem)
        $movies = get_posts(['post_type' => 'movie', 'posts_per_page' => 10]);
        foreach ($movies as $movie) {
            Relations::load($movie, 'actors');
        }
        
        $lazy_queries = $wpdb->num_queries;
        
        // Should be 11+ queries: 1 for movies, 10 for actors
        $this->assertGreaterThan(10, $lazy_queries);
        
        // Eager loading should be significantly better
        $this->assertLessThan($lazy_queries / 3, $eager_queries);
    }
    
    public function test_query_performance_with_large_dataset(): void {
        // Create 1000 movies with 10 actors each
        $start_time = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $movie_id = $this->factory->post->create(['post_type' => 'movie']);
            
            for ($j = 0; $j < 10; $j++) {
                $actor_id = $this->factory->post->create(['post_type' => 'person']);
                Relations::create('movie', $movie_id, 'actors', $actor_id);
            }
        }
        
        $setup_time = microtime(true) - $start_time;
        
        // Query 100 movies with actors
        $start_time = microtime(true);
        
        $movies = Relations::for('movie')
            ->with('actors')
            ->get(['posts_per_page' => 100]);
        
        $query_time = microtime(true) - $start_time;
        
        // Should complete in under 1 second
        $this->assertLessThan(1.0, $query_time);
        
        // Verify we got results
        $this->assertCount(100, $movies);
        
        // p95 should be under 100ms for production
        echo "\nQuery time for 100 movies with 10 actors each: " . round($query_time * 1000, 2) . "ms\n";
    }
}
```

---

## Browser/E2E Tests

**Tool:** Playwright or WordPress e2e test framework

**File:** `tests/e2e/relationship-metabox.spec.js`

```javascript
const { test, expect } = require('@wordpress/e2e-test-utils-playwright');

test.describe('Relationship Metabox', () => {
  test.beforeAll(async ({ requestUtils }) => {
    // Create test posts
    await requestUtils.createPost({
      title: 'Test Movie',
      status: 'publish',
      type: 'movie',
    });
  });

  test('displays relationship metabox on movie edit screen', async ({ page, admin }) => {
    await admin.visitAdminPage('edit.php', 'post_type=movie');
    
    // Click first movie
    await page.click('.row-title');
    
    // Should see actors metabox
    await expect(page.locator('#saltus_relationship_actors')).toBeVisible();
  });

  test('can select related post with Select2', async ({ page, admin }) => {
    await admin.createNewPost({ postType: 'movie' });
    
    // Fill title
    await page.fill('#title', 'New Movie');
    
    // Open Select2
    await page.click('.saltus-relationship-select');
    
    // Search for actor
    await page.fill('.select2-search__field', 'Daniel Craig');
    
    // Wait for results
    await page.waitForSelector('.select2-results');
    
    // Click first result
    await page.click('.select2-results__option:first-child');
    
    // Publish
    await page.click('#publish');
    
    // Verify saved
    await page.waitForSelector('.notice-success');
    
    // Reload and check persisted
    await page.reload();
    
    const selectedValue = await page.inputValue('.saltus-relationship-select');
    expect(selectedValue).toContain('Daniel Craig');
  });

  test('can add pivot data for many-to-many relationships', async ({ page, admin }) => {
    await admin.createNewPost({ postType: 'movie' });
    
    // Select actor
    await page.click('.saltus-relationship-select');
    await page.click('.select2-results__option:first-child');
    
    // Fill pivot field (role)
    await page.fill('input[name="saltus_rel_actors_pivot[0][role]"]', 'James Bond');
    
    // Publish
    await page.click('#publish');
    await page.waitForSelector('.notice-success');
    
    // Reload and verify
    await page.reload();
    const role = await page.inputValue('input[name="saltus_rel_actors_pivot[0][role]"]');
    expect(role).toBe('James Bond');
  });

  test('can reorder has_many relationships via drag-and-drop', async ({ page, admin }) => {
    // This test requires actual drag-and-drop implementation
    // TODO: Implement when UI is ready
  });
});
```

---

## Test Data Fixtures

**File:** `tests/fixtures/relationships.php`

```php
<?php
return [
    'models' => [
        'movie' => [
            'type' => 'cpt',
            'name' => 'movie',
            'relationships' => [
                'director' => [
                    'type' => 'has_one',
                    'model' => 'person',
                    'reciprocal' => 'directed_movies',
                ],
                'actors' => [
                    'type' => 'has_many',
                    'model' => 'person',
                    'reciprocal' => 'acted_in',
                    'meta' => [
                        'role' => ['type' => 'text'],
                        'screen_time' => ['type' => 'number'],
                    ],
                ],
            ],
        ],
        'person' => [
            'type' => 'cpt',
            'name' => 'person',
        ],
    ],
    
    'sample_data' => [
        'movies' => [
            ['title' => 'Skyfall', 'year' => 2012],
            ['title' => 'Casino Royale', 'year' => 2006],
            ['title' => 'Spectre', 'year' => 2015],
        ],
        'people' => [
            ['title' => 'Daniel Craig', 'role' => 'Actor'],
            ['title' => 'Sam Mendes', 'role' => 'Director'],
            ['title' => 'Judi Dench', 'role' => 'Actor'],
        ],
    ],
];
```

---

## Continuous Integration

### GitHub Actions Workflow

**File:** `.github/workflows/test-relationships.yml`

```yaml
name: Test Relationships

on:
  pull_request:
    paths:
      - 'src/Features/Relationships/**'
      - 'tests/**'

jobs:
  phpunit:
    runs-on: ubuntu-latest
    
    strategy:
      matrix:
        php: ['7.4', '8.0', '8.1', '8.2']
        wordpress: ['6.4', '6.5', '7.0']
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mysqli
      
      - name: Install Composer dependencies
        run: composer install --prefer-dist
      
      - name: Setup WordPress test environment
        run: bash bin/install-wp-tests.sh wordpress_test root '' localhost ${{ matrix.wordpress }}
      
      - name: Run PHPUnit tests
        run: vendor/bin/phpunit --testsuite=relationships --coverage-clover=coverage.xml
      
      - name: Upload coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage.xml
  
  performance:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Run performance tests
        run: vendor/bin/phpunit --group=performance
      
      - name: Check performance thresholds
        run: |
          # Fail if p95 > 100ms
          php tests/check-performance.php
```

---

## Test Execution Schedule

### Pre-Commit
- PHPUnit unit tests (fast)
- PHPStan level 7
- PHPCS

### Pull Request
- Full PHPUnit suite
- Performance tests
- Browser tests (on changed UI files)

### Nightly
- Full suite against WordPress trunk
- Load tests with 100K posts
- Memory profiling

---

## Acceptance Criteria

### Phase 10A Complete When:

- ✅ 85%+ code coverage
- ✅ All unit tests pass
- ✅ All integration tests pass
- ✅ REST API tests pass
- ✅ Performance: p95 < 100ms for relationship queries
- ✅ Performance: No N+1 queries with eager loading
- ✅ Browser tests: Metabox UI functional
- ✅ Zero PHPStan errors (Level 7)
- ✅ Zero PHPCS errors

### Performance Benchmarks

| Metric | Target | Measured |
|--------|--------|----------|
| Create relationship | < 50ms | TBD |
| Query 100 posts with 10 relationships each | < 100ms | TBD |
| Eager load 3 relationships | < 3 queries | TBD |
| Sync 100 relationships | < 500ms | TBD |

---

**Document Status:** Ready for QA Review  
**Next Steps:** Begin test implementation in Week 2  
**Owner:** QA Engineer + Backend Engineer (TDD)
