<?php
/**
 * PHP Version 5.4
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 3.0.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace Cake\Test\TestCase\ORM;

use Cake\Core\Configure;
use Cake\Database\ConnectionManager;
use Cake\Database\Expression\OrderByExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\ORM\Query;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * Tests Query class
 *
 */
class QueryTest extends TestCase {

/**
 * Fixture to be used
 *
 * @var array
 */
	public $fixtures = ['core.article', 'core.author', 'core.tag',
		'core.articles_tag', 'core.post'];

/**
 * setUp method
 *
 * @return void
 */
	public function setUp() {
		parent::setUp();
		$this->connection = ConnectionManager::get('test');
		$schema = ['id' => ['type' => 'integer']];
		$schema1 = [
			'id' => ['type' => 'integer'],
			'name' => ['type' => 'string'],
			'phone' => ['type' => 'string']
		];
		$schema2 = [
			'id' => ['type' => 'integer'],
			'total' => ['type' => 'string'],
			'placed' => ['type' => 'datetime']
		];

		$this->table = $table = Table::build('foo', ['schema' => $schema]);
		$clients = Table::build('client', ['schema' => $schema1]);
		$orders = Table::build('order', ['schema' => $schema2]);
		$companies = Table::build('company', ['schema' => $schema, 'table' => 'organizations']);
		$orderTypes = Table::build('orderType', ['schema' => $schema]);
		$stuff = Table::build('stuff', ['schema' => $schema, 'table' => 'things']);
		$stuffTypes = Table::build('stuffType', ['schema' => $schema]);
		$categories = Table::build('category', ['schema' => $schema]);

		$table->belongsTo('client');
		$clients->hasOne('order');
		$clients->belongsTo('company');
		$orders->belongsTo('orderType');
		$orders->hasOne('stuff');
		$stuff->belongsTo('stuffType');
		$companies->belongsTo('category');
	}

/**
 * tearDown method
 *
 * @return void
 */
	public function tearDown() {
		parent::tearDown();
		Table::clearRegistry();
	}

/**
 * Tests that fully defined belongsTo and hasOne relationships are joined correctly
 *
 * @return void
 **/
	public function testContainToJoinsOneLevel() {
		$contains = [
			'client' => [
			'order' => [
					'orderType',
					'stuff' => ['stuffType']
				],
				'company' => [
					'foreignKey' => 'organization_id',
					'category'
				]
			]
		];

		$query = $this->getMock('\Cake\ORM\Query', ['join'], [$this->connection, $this->table]);

		$query->expects($this->at(0))->method('join')
			->with(['client' => [
				'table' => 'clients',
				'type' => 'LEFT',
				'conditions' => ['client.id = foo.client_id']
			]])
			->will($this->returnValue($query));

		$query->expects($this->at(1))->method('join')
			->with(['order' => [
				'table' => 'orders',
				'type' => 'INNER',
				'conditions' => ['client.id = order.client_id']
			]])
			->will($this->returnValue($query));

		$query->expects($this->at(2))->method('join')
			->with(['orderType' => [
				'table' => 'order_types',
				'type' => 'LEFT',
				'conditions' => ['orderType.id = order.order_type_id']
			]])
			->will($this->returnValue($query));

		$query->expects($this->at(3))->method('join')
			->with(['stuff' => [
				'table' => 'things',
				'type' => 'INNER',
				'conditions' => ['order.id = stuff.order_id']
			]])
			->will($this->returnValue($query));

		$query->expects($this->at(4))->method('join')
			->with(['stuffType' => [
				'table' => 'stuff_types',
				'type' => 'LEFT',
				'conditions' => ['stuffType.id = stuff.stuff_type_id']
			]])
			->will($this->returnValue($query));

		$query->expects($this->at(5))->method('join')
			->with(['company' => [
				'table' => 'organizations',
				'type' => 'LEFT',
				'conditions' => ['company.id = client.organization_id']
			]])
			->will($this->returnValue($query));

		$query->expects($this->at(6))->method('join')
			->with(['category' => [
				'table' => 'categories',
				'type' => 'LEFT',
				'conditions' => ['category.id = company.category_id']
			]])
			->will($this->returnValue($query));

		$s = $query
			->select('foo.id')
			->contain($contains)->sql();
	}

/**
 * Test that fields for contained models are aliased and added to the select clause
 *
 * @return void
 **/
	public function testContainToFieldsPredefined() {
		$contains = [
			'client' => [
				'fields' => ['name', 'company_id', 'client.telephone'],
				'order' => [
					'fields' => ['total', 'placed']
				]
			]
		];

		$table = Table::build('foo', ['schema' => ['id' => ['type' => 'integer']]]);
		$query = new Query($this->connection, $table);

		$query->select('foo.id')->contain($contains)->sql();
		$select = $query->clause('select');
		$expected = [
			'foo__id' => 'foo.id', 'client__name' => 'client.name',
			'client__company_id' => 'client.company_id',
			'client__telephone' => 'client.telephone',
			'order__total' => 'order.total', 'order__placed' => 'order.placed'
		];
		$this->assertEquals($expected, $select);
	}

/**
 * Tests that default fields for associations are added to the select clause when
 * none is specified
 *
 * @return void
 **/
	public function testContainToFieldsDefault() {
		$contains = ['client' => ['order']];

		$query = new Query($this->connection, $this->table);
		$query->select()->contain($contains)->sql();
		$select = $query->clause('select');
		$expected = [
			'foo__id' => 'foo.id', 'client__name' => 'client.name',
			'client__id' => 'client.id', 'client__phone' => 'client.phone',
			'order__id' => 'order.id', 'order__total' => 'order.total',
			'order__placed' => 'order.placed'
		];
		$this->assertEquals($expected, $select);

		$contains['client']['fields'] = ['name'];
		$query = new Query($this->connection, $this->table);
		$query->select('foo.id')->contain($contains)->sql();
		$select = $query->clause('select');
		$expected = ['foo__id' => 'foo.id', 'client__name' => 'client.name'];
		$this->assertEquals($expected, $select);

		$contains['client']['fields'] = [];
		$contains['client']['order']['fields'] = false;
		$query = new Query($this->connection, $this->table);
		$query->select()->contain($contains)->sql();
		$select = $query->clause('select');
		$expected = [
			'foo__id' => 'foo.id',
			'client__id' => 'client.id',
			'client__name' => 'client.name',
			'client__phone' => 'client.phone',
		];
		$this->assertEquals($expected, $select);
	}

/**
 * Tests that results are grouped correctly when using contain()
 * and results are not hydrated
 *
 * @return void
 **/
	public function testContainResultFetchingOneLevelNoHydration() {
		$table = Table::build('article', ['table' => 'articles']);
		$table->belongsTo('author');

		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain('author')
			->hydrate(false)
			->order(['article.id' => 'asc'])
			->toArray();
		$expected = [
			[
				'id' => 1,
				'title' => 'First Article',
				'body' => 'First Article Body',
				'author_id' => 1,
				'published' => 'Y',
				'author' => [
					'id' => 1,
					'name' => 'mariano'
				]
			],
			[
				'id' => 2,
				'title' => 'Second Article',
				'body' => 'Second Article Body',
				'author_id' => 3,
				'published' => 'Y',
				'author' => [
					'id' => 3,
					'name' => 'larry'
				]
			],
			[
				'id' => 3,
				'title' => 'Third Article',
				'body' => 'Third Article Body',
				'author_id' => 1,
				'published' => 'Y',
				'author' => [
					'id' => 1,
					'name' => 'mariano'
				]
			],
		];
		$this->assertEquals($expected, $results);
	}

/**
 * Data provider for the two types of strategies HasMany implements
 *
 * @return void
 */
	public function strategiesProvider() {
		return [['subquery'], ['select']];
	}

/**
 * Tests that HasMany associations are correctly eager loaded and results
 * correctly nested when no hydration is used
 * Also that the query object passes the correct parent model keys to the
 * association objects in order to perform eager loading with select strategy
 *
 * @dataProvider strategiesProvider
 * @return void
 **/
	public function testHasManyEagerLoadingNoHydration($strategy) {
		$table = Table::build('author', ['connection' => $this->connection]);
		Table::build('article', ['connection' => $this->connection]);
		$table->hasMany('article', [
			'property' => 'articles',
			'strategy' => $strategy,
			'sort' => ['article.id' => 'asc']
		]);
		$query = new Query($this->connection, $table);

		$results = $query->select()
			->contain('article')
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 1,
				'name' => 'mariano',
				'articles' => [
					[
						'id' => 1,
						'title' => 'First Article',
						'body' => 'First Article Body',
						'author_id' => 1,
						'published' => 'Y',
					],
					[
						'id' => 3,
						'title' => 'Third Article',
						'body' => 'Third Article Body',
						'author_id' => 1,
						'published' => 'Y',
					],
				]
			],
			[
				'id' => 2,
				'name' => 'nate',
			],
			[
				'id' => 3,
				'name' => 'larry',
				'articles' => [
					[
						'id' => 2,
						'title' => 'Second Article',
						'body' => 'Second Article Body',
						'author_id' => 3,
						'published' => 'Y'
					]
				]
			],
			[
				'id' => 4,
				'name' => 'garrett',
			]
		];
		$this->assertEquals($expected, $results);

		$results = $query->repository($table)
			->select()
			->contain(['article' => ['conditions' => ['id' => 2]]])
			->hydrate(false)
			->toArray();
		unset($expected[0]['articles']);
		$this->assertEquals($expected, $results);
		$this->assertEquals($table->association('article')->strategy(), $strategy);
	}

/**
 * Tests that it is possible to set fields & order in a hasMany result set
 *
 * @dataProvider strategiesProvider
 * @return void
 **/
	public function testHasManyEagerLoadingFieldsAndOrderNoHydration($strategy) {
		$table = Table::build('author', ['connection' => $this->connection]);
		Table::build('article', ['connection' => $this->connection]);
		$table->hasMany('article', ['property' => 'articles'] + compact('strategy'));

		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain([
				'article' => [
					'fields' => ['title', 'author_id'],
					'sort' => ['id' => 'DESC']
				]
			])
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 1,
				'name' => 'mariano',
				'articles' => [
					['title' => 'Third Article', 'author_id' => 1],
					['title' => 'First Article', 'author_id' => 1],
				]
			],
			[
				'id' => 2,
				'name' => 'nate',
			],
			[
				'id' => 3,
				'name' => 'larry',
				'articles' => [
					['title' => 'Second Article', 'author_id' => 3],
				]
			],
			[
				'id' => 4,
				'name' => 'garrett',
			],
		];
		$this->assertEquals($expected, $results);
	}

/**
 * Tests that deep associations can be eagerly loaded
 *
 * @dataProvider strategiesProvider
 * @return void
 **/
	public function testHasManyEagerLoadingDeepNoHydration($strategy) {
		$table = Table::build('author');
		$article = Table::build('article');
		$table->hasMany('article', [
			'property' => 'articles',
			'stratgey' => $strategy,
			'sort' => ['article.id' => 'asc']
		]);
		$article->belongsTo('author');
		$query = new Query($this->connection, $table);

		$results = $query->select()
			->contain(['article' => ['author']])
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 1,
				'name' => 'mariano',
				'articles' => [
					[
						'id' => 1,
						'title' => 'First Article',
						'author_id' => 1,
						'body' => 'First Article Body',
						'published' => 'Y',
						'author' => ['id' => 1, 'name' => 'mariano']
					],
					[
						'id' => 3,
						'title' => 'Third Article',
						'author_id' => 1,
						'body' => 'Third Article Body',
						'published' => 'Y',
						'author' => ['id' => 1, 'name' => 'mariano']
					],
				]
			],
			[
				'id' => 2,
				'name' => 'nate'
			],
			[
				'id' => 3,
				'name' => 'larry',
				'articles' => [
					[
						'id' => 2,
						'title' => 'Second Article',
						'author_id' => 3,
						'body' => 'Second Article Body',
						'published' => 'Y',
						'author' => ['id' => 3, 'name' => 'larry']
					],
				]
			],
			[
				'id' => 4,
				'name' => 'garrett'
			]
		];
		$this->assertEquals($expected, $results);
	}

/**
 * Tests that hasMany associations can be loaded even when related to a secondary
 * model in the query
 *
 * @dataProvider strategiesProvider
 * @return void
 **/
	public function testHasManyEagerLoadingFromSecondaryTable($strategy) {
		$author = Table::build('author');
		$article = Table::build('article');
		$post = Table::build('post');

		$author->hasMany('post', ['property' => 'posts'] + compact('strategy'));
		$article->belongsTo('author');

		$query = new Query($this->connection, $article);

		$results = $query->select()
			->contain(['author' => ['post']])
			->order(['article.id' => 'ASC'])
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 1,
				'title' => 'First Article',
				'body' => 'First Article Body',
				'author_id' => 1,
				'published' => 'Y',
				'author' => [
					'id' => 1,
					'name' => 'mariano',
					'posts' => [
						[
							'id' => '1',
							'title' => 'First Post',
							'body' => 'First Post Body',
							'author_id' => 1,
							'published' => 'Y',
						],
						[
							'id' => '3',
							'title' => 'Third Post',
							'body' => 'Third Post Body',
							'author_id' => 1,
							'published' => 'Y',
						],
					]
				]
			],
			[
				'id' => 2,
				'title' => 'Second Article',
				'body' => 'Second Article Body',
				'author_id' => 3,
				'published' => 'Y',
				'author' => [
					'id' => 3,
					'name' => 'larry',
					'posts' => [
						[
							'id' => 2,
							'title' => 'Second Post',
							'body' => 'Second Post Body',
							'author_id' => 3,
							'published' => 'Y',
						]
					]
				]
			],
			[
				'id' => 3,
				'title' => 'Third Article',
				'body' => 'Third Article Body',
				'author_id' => 1,
				'published' => 'Y',
				'author' => [
					'id' => 1,
					'name' => 'mariano',
					'posts' => [
						[
							'id' => '1',
							'title' => 'First Post',
							'body' => 'First Post Body',
							'author_id' => 1,
							'published' => 'Y',
						],
						[
							'id' => '3',
							'title' => 'Third Post',
							'body' => 'Third Post Body',
							'author_id' => 1,
							'published' => 'Y',
						],
					]
				]
			],
		];
		$this->assertEquals($expected, $results);
	}

/**
 * Tests that BelongsToMany associations are correctly eager loaded.
 * Also that the query object passes the correct parent model keys to the
 * association objects in order to perform eager loading with select strategy
 *
 * @dataProvider strategiesProvider
 * @return void
 **/
	public function testBelongsToManyEagerLoadingNoHydration($strategy) {
		$table = Table::build('Article');
		$table->belongsToMany('Tag', ['property' => 'tags', 'strategy' => $strategy]);
		$query = new Query($this->connection, $table);

		$results = $query->select()->contain('Tag')->hydrate(false)->toArray();
		$expected = [
			[
				'id' => 1,
				'author_id' => 1,
				'title' => 'First Article',
				'body' => 'First Article Body',
				'published' => 'Y',
				'tags' => [
					[
						'id' => 1,
						'name' => 'tag1',
						'ArticlesTag' => ['article_id' => 1, 'tag_id' => 1]
					],
					[
						'id' => 2,
						'name' => 'tag2',
						'ArticlesTag' => ['article_id' => 1, 'tag_id' => 2]
					]
				]
			],
			[
				'id' => 2,
				'title' => 'Second Article',
				'body' => 'Second Article Body',
				'author_id' => 3,
				'published' => 'Y',
				'tags' => [
					[
						'id' => 1,
						'name' => 'tag1',
						'ArticlesTag' => ['article_id' => 2, 'tag_id' => 1]
					],
					[
						'id' => 3,
						'name' => 'tag3',
						'ArticlesTag' => ['article_id' => 2, 'tag_id' => 3]
					]
				]
			],
			[
				'id' => 3,
				'title' => 'Third Article',
				'body' => 'Third Article Body',
				'author_id' => 1,
				'published' => 'Y',
			],
		];
		$this->assertEquals($expected, $results);

		$results = $query->select()
			->contain(['Tag' => ['conditions' => ['id' => 3]]])
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 1,
				'author_id' => 1,
				'title' => 'First Article',
				'body' => 'First Article Body',
				'published' => 'Y',
			],
			[
				'id' => 2,
				'title' => 'Second Article',
				'body' => 'Second Article Body',
				'author_id' => 3,
				'published' => 'Y',
				'tags' => [
					[
						'id' => 3,
						'name' => 'tag3',
						'ArticlesTag' => ['article_id' => 2, 'tag_id' => 3]
					]
				]
			],
			[
				'id' => 3,
				'title' => 'Third Article',
				'body' => 'Third Article Body',
				'author_id' => 1,
				'published' => 'Y',
			],
		];
		$this->assertEquals($expected, $results);
		$this->assertEquals($table->association('Tag')->strategy(), $strategy);
	}

/**
 * Tests that tables results can be filtered by the result of a HasMany
 *
 * @return void
 */
	public function testFilteringByHasManyNoHydration() {
		$query = new Query($this->connection, $this->table);
		$table = Table::build('author');
		$table->hasMany('article', ['property' => 'articles']);

		$results = $query->repository($table)
			->select()
			->hydrate(false)
			->contain(['article' => [
				'matching' => true,
				'conditions' => ['article.id' => 2]
			]])
			->toArray();
		$expected = [
			[
				'id' => 3,
				'name' => 'larry',
				'articles' => [
					'id' => 2,
					'title' => 'Second Article',
					'body' => 'Second Article Body',
					'author_id' => 3,
					'published' => 'Y',
				]
			]
		];
		$this->assertEquals($expected, $results);
	}

/**
 * Tests that BelongsToMany associations are correctly eager loaded.
 * Also that the query object passes the correct parent model keys to the
 * association objects in order to perform eager loading with select strategy
 *
 * @return void
 **/
	public function testFilteringByBelongsToManyNoHydration() {
		$query = new Query($this->connection, $this->table);
		$table = Table::build('Article');
		$table->belongsToMany('Tag', ['property' => 'tags']);

		$results = $query->repository($table)->select()
			->contain(['Tag' => [
				'matching' => true,
				'conditions' => ['Tag.id' => 3]
			]])
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 2,
				'author_id' => 3,
				'title' => 'Second Article',
				'body' => 'Second Article Body',
				'published' => 'Y',
				'tags' => [
					'id' => 3,
					'name' => 'tag3'
				]
			]
		];
		$this->assertEquals($expected, $results);

		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain(['Tag' => [
				'matching' => true,
				'conditions' => ['Tag.name' => 'tag2']]
			])
			->hydrate(false)
			->toArray();
		$expected = [
			[
				'id' => 1,
				'title' => 'First Article',
				'body' => 'First Article Body',
				'author_id' => 1,
				'published' => 'Y',
				'tags' => [
					'id' => 2,
					'name' => 'tag2'
				]
			]
		];
		$this->assertEquals($expected, $results);
	}

/**
 * Test setResult()
 *
 * @return void
 */
	public function testSetResult() {
		$query = new Query($this->connection, $this->table);
		$stmt = $this->getMock('Cake\Database\StatementInterface');
		$results = new ResultSet($query, $stmt);
		$query->setResult($results);
		$this->assertSame($results, $query->execute());
	}

/**
 * Test enabling buffering of results.
 *
 * @return void
 */
	public function testBufferResults() {
		$table = Table::build('article');
		$query = new Query($this->connection, $table);

		$result = $query->select()->bufferResults();
		$this->assertSame($query, $result, 'Query should be the same');
		$result = $query->execute();
		$this->assertInstanceOf('Cake\ORM\BufferedResultSet', $result);
	}

/**
 * Tests that applying array options to a query will convert them
 * to equivalent function calls with the correspondent array values
 *
 * @return void
 */
	public function testApplyOptions() {
		$options = [
			'fields' => ['field_a', 'field_b'],
			'conditions' => ['field_a' => 1, 'field_b' => 'something'],
			'limit' => 1,
			'order' => ['a' => 'ASC'],
			'offset' => 5,
			'group' => ['field_a'],
			'having' => ['field_a >' => 100],
			'contain' => ['table_a' => ['table_b']],
			'join' => ['table_a' => ['conditions' => ['a > b']]]
		];
		$query = new Query($this->connection, $this->table);
		$query->applyOptions($options);

		$this->assertEquals(['field_a', 'field_b'], $query->clause('select'));

		$expected = new QueryExpression($options['conditions']);
		$result = $query->clause('where');
		$this->assertEquals($expected, $result);

		$this->assertEquals(1, $query->clause('limit'));

		$expected = new QueryExpression(['a > b']);
		$result = $query->clause('join');
		$this->assertEquals([
			['alias' => 'table_a', 'type' => 'INNER', 'conditions' => $expected]
		], $result);

		$expected = new OrderByExpression(['a' => 'ASC']);
		$this->assertEquals($expected, $query->clause('order'));

		$this->assertEquals(5, $query->clause('offset'));
		$this->assertEquals(['field_a'], $query->clause('group'));

		$expected = new QueryExpression($options['having']);
		$this->assertEquals($expected, $query->clause('having'));

		$expected = new \ArrayObject(['table_a' => ['table_b' => []]]);
		$this->assertEquals($expected, $query->contain());
	}

/**
 * Tests getOptions() method
 *
 * @return void
 */
	public function testGetOptions() {
		$options = ['doABarrelRoll' => true, 'fields' => ['id', 'name']];
		$query = new Query($this->connection, $this->table);
		$query->applyOptions($options);
		$expected = ['doABarrelRoll' => true];
		$this->assertEquals($expected, $query->getOptions());

		$expected = ['doABarrelRoll' => false, 'doAwesome' => true];
		$query->applyOptions($expected);
		$this->assertEquals($expected, $query->getOptions());
	}

/**
 * Tests registering mappers with mapReduce()
 *
 * @return void
 */
	public function testMapReduceOnlyMapper() {
		$mapper1 = function() {
		};
		$mapper2 = function() {
		};
		$query = new Query($this->connection, $this->table);
		$this->assertSame($query, $query->mapReduce($mapper1));
		$this->assertEquals(
			[['mapper' => $mapper1, 'reducer' => null]],
			$query->mapReduce()
		);

		$this->assertEquals($query, $query->mapReduce($mapper1));
		$result = $query->mapReduce();
		$this->assertEquals(
			[
				['mapper' => $mapper1, 'reducer' => null],
				['mapper' => $mapper2, 'reducer' => null]
			],
			$result
		);
	}

/**
 * Tests registering mappers and reducers with mapReduce()
 *
 * @return void
 */
	public function testMapReduceBothMethods() {
		$mapper1 = function() {
		};
		$mapper2 = function() {
		};
		$reducer1 = function() {
		};
		$reducer2 = function() {
		};
		$query = new Query($this->connection, $this->table);
		$this->assertSame($query, $query->mapReduce($mapper1, $reducer1));
		$this->assertEquals(
			[['mapper' => $mapper1, 'reducer' => $reducer1]],
			$query->mapReduce()
		);

		$this->assertSame($query, $query->mapReduce($mapper2, $reducer2));
		$this->assertEquals(
			[
				['mapper' => $mapper1, 'reducer' => $reducer1],
				['mapper' => $mapper2, 'reducer' => $reducer2]
			],
			$query->mapReduce()
		);
	}

/**
 * Tests that it is possible to overwrite previous map reducers
 *
 * @return void
 */
	public function testOverwriteMapReduce() {
		$mapper1 = function() {
		};
		$mapper2 = function() {
		};
		$reducer1 = function() {
		};
		$reducer2 = function() {
		};
		$query = new Query($this->connection, $this->table);
		$this->assertEquals($query, $query->mapReduce($mapper1, $reducer1));
		$this->assertEquals(
			[['mapper' => $mapper1, 'reducer' => $reducer1]],
			$query->mapReduce()
		);

		$this->assertEquals($query, $query->mapReduce($mapper2, $reducer2, true));
		$this->assertEquals(
			[['mapper' => $mapper2, 'reducer' => $reducer2]],
			$query->mapReduce()
		);
	}

/**
 * Tests that multiple map reducers can be stacked
 *
 * @return void
 */
	public function testResultsAreWrappedInMapReduce() {
		$params = [$this->connection, $this->table];
		$query = $this->getMock('\Cake\ORM\Query', ['executeStatement'], $params);

		$statement = $this->getMock('\Database\StatementInterface', ['fetch']);
		$statement->expects($this->exactly(3))
			->method('fetch')
			->will($this->onConsecutiveCalls(['a' => 1], ['a' => 2], false));

		$query->expects($this->once())
			->method('executeStatement')
			->will($this->returnValue($statement));

		$query->mapReduce(function($k, $v, $mr) {
			$mr->emit($v['a']);
		});
		$query->mapReduce(
			function($k, $v, $mr) {
				$mr->emitIntermediate($k, $v);
			},
			function($k, $v, $mr) {
				$mr->emit($v[0] + 1);
			}
		);

		$this->assertEquals([2, 3], iterator_to_array($query->execute()));
	}

/**
 * Tests first() method when the query has not been executed before
 *
 * @return void
 */
	public function testFirstDirtyQuery() {
		$table = Table::build('article', ['table' => 'articles']);
		$query = new Query($this->connection, $table);
		$result = $query->select(['id'])->hydrate(false)->first();
		$this->assertEquals(['id' => 1], $result);
		$this->assertEquals(1, $query->clause('limit'));
		$result = $query->select(['id'])->first();
		$this->assertEquals(['id' => 1], $result);
	}

/**
 * Tests that first can be called again on an already executed query
 *
 * @return void
 */
	public function testFirstCleanQuery() {
		$table = Table::build('article', ['table' => 'articles']);
		$query = new Query($this->connection, $table);
		$query->select(['id'])->toArray();

		$first = $query->hydrate(false)->first();
		$this->assertEquals(['id' => 1], $first);
		$this->assertNull($query->clause('limit'));
	}

/**
 * Tests that first() will not execute the same query twice
 *
 * @return void
 */
	public function testFirstSameResult() {
		$table = Table::build('article', ['table' => 'articles']);
		$query = new Query($this->connection, $table);
		$query->select(['id'])->toArray();

		$first = $query->hydrate(false)->first();
		$resultSet = $query->execute();
		$this->assertEquals(['id' => 1], $first);
		$this->assertSame($resultSet, $query->execute());
	}

/**
 * Testing hydrating a result set into Entity objects
 *
 * @return void
 */
	public function testHydrateSimple() {
		$table = Table::build('article', ['table' => 'articles']);
		$query = new Query($this->connection, $table);
		$results = $query->select()->execute()->toArray();

		$this->assertCount(3, $results);
		foreach ($results as $r) {
			$this->assertInstanceOf('\Cake\ORM\Entity', $r);
		}

		$first = $results[0];
		$this->assertEquals(1, $first->id);
		$this->assertEquals(1, $first->author_id);
		$this->assertEquals('First Article', $first->title);
		$this->assertEquals('First Article Body', $first->body);
		$this->assertEquals('Y', $first->published);
	}

/**
 * Tests that has many results are also hydrated correctly
 *
 * @return void
 */
	public function testHydrateWithHasMany() {
		$table = Table::build('author', ['connection' => $this->connection]);
		Table::build('article', ['connection' => $this->connection]);
		$table->hasMany('article', [
			'property' => 'articles',
			'sort' => ['article.id' => 'asc']
		]);
		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain('article')
			->toArray();

		$first = $results[0];
		foreach ($first->articles as $r) {
			$this->assertInstanceOf('\Cake\ORM\Entity', $r);
		}

		$this->assertCount(2, $first->articles);
		$expected = [
			'id' => 1,
			'title' => 'First Article',
			'body' => 'First Article Body',
			'author_id' => 1,
			'published' => 'Y',
		];
		$this->assertEquals($expected, $first->articles[0]->toArray());
		$expected = [
			'id' => 3,
			'title' => 'Third Article',
			'author_id' => 1,
			'body' => 'Third Article Body',
			'published' => 'Y',
		];
		$this->assertEquals($expected, $first->articles[1]->toArray());
	}

/**
 * Tests that belongsToMany associations are also correctly hydrated
 *
 * @return void
 */
	public function testHydrateBelongsToMany() {
		$table = Table::build('Article', ['connection' => $this->connection]);
		Table::build('Tag', ['connection' => $this->connection]);
		Table::build('ArticlesTag', [
			'connection' => $this->connection,
			'table' => 'articles_tags'
		]);
		$table->belongsToMany('Tag', ['property' => 'tags']);
		$query = new Query($this->connection, $table);

		$results = $query
			->select()
			->contain('Tag')
			->toArray();

		$first = $results[0];
		foreach ($first->tags as $r) {
			$this->assertInstanceOf('\Cake\ORM\Entity', $r);
		}

		$this->assertCount(2, $first->tags);
		$expected = [
			'id' => 1,
			'name' => 'tag1',
			'ArticlesTag' => new \Cake\ORM\Entity(['article_id' => 1, 'tag_id' => 1])
		];
		$this->assertEquals($expected, $first->tags[0]->toArray());

		$expected = [
			'id' => 2,
			'name' => 'tag2',
			'ArticlesTag' => new \Cake\ORM\Entity(['article_id' => 1, 'tag_id' => 2])
		];
		$this->assertEquals($expected, $first->tags[1]->toArray());
	}

/**
 * Tests that belongsTo relations are correctly hydrated
 *
 * @return void
 */
	public function testHydrateBelongsTo() {
		$table = Table::build('article', ['table' => 'articles']);
		Table::build('author', ['connection' => $this->connection]);
		$table->belongsTo('author');

		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain('author')
			->order(['article.id' => 'asc'])
			->toArray();

		$this->assertCount(3, $results);
		$first = $results[0];
		$this->assertInstanceOf('\Cake\ORM\Entity', $first->author);
		$expected = ['id' => 1, 'name' => 'mariano'];
		$this->assertEquals($expected, $first->author->toArray());
	}

/**
 * Tests that deeply nested associations are also hydrated correctly
 *
 * @return void
 */
	public function testHydrateDeep() {
		$table = Table::build('author', ['connection' => $this->connection]);
		$article = Table::build('article', ['connection' => $this->connection]);
		$table->hasMany('article', [
			'property' => 'articles',
			'sort' => ['article.id' => 'asc']
		]);
		$article->belongsTo('author');
		$query = new Query($this->connection, $table);

		$results = $query->select()
			->contain(['article' => ['author']])
			->toArray();

		$this->assertCount(4, $results);
		$first = $results[0];
		$this->assertInstanceOf('\Cake\ORM\Entity', $first->articles[0]->author);
		$expected = ['id' => 1, 'name' => 'mariano'];
		$this->assertEquals($expected, $first->articles[0]->author->toArray());
		$this->assertFalse(isset($results[3]->articles));
	}

/**
 * Tests that it is possible to use a custom entity class
 *
 * @return void
 */
	public function testHydrateCustomObject() {
		$class = $this->getMockClass('\Cake\ORM\Entity', ['fakeMethod']);
		$table = Table::build('article', [
			'table' => 'articles',
			'entityClass' => '\\' . $class
		]);
		$query = new Query($this->connection, $table);
		$results = $query->select()->execute()->toArray();

		$this->assertCount(3, $results);
		foreach ($results as $r) {
			$this->assertInstanceOf($class, $r);
		}

		$first = $results[0];
		$this->assertEquals(1, $first->id);
		$this->assertEquals(1, $first->author_id);
		$this->assertEquals('First Article', $first->title);
		$this->assertEquals('First Article Body', $first->body);
		$this->assertEquals('Y', $first->published);
	}

/**
 * Tests that has many results are also hydrated correctly
 * when specified a custom entity class
 *
 * @return void
 */
	public function testHydrateWithHasManyCustomEntity() {
		$authorEntity = $this->getMockClass('\Cake\ORM\Entity', ['foo']);
		$articleEntity = $this->getMockClass('\Cake\ORM\Entity', ['foo']);
		$table = Table::build('author', [
			'connection' => $this->connection,
			'entityClass' => '\\' . $authorEntity
		]);
		Table::build('article', [
			'connection' => $this->connection,
			'entityClass' => '\\' . $articleEntity
		]);
		$table->hasMany('article', [
			'property' => 'articles',
			'sort' => ['article.id' => 'asc']
		]);
		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain('article')
			->toArray();

		$first = $results[0];
		$this->assertInstanceOf($authorEntity, $first);
		foreach ($first->articles as $r) {
			$this->assertInstanceOf($articleEntity, $r);
		}

		$this->assertCount(2, $first->articles);
		$expected = [
			'id' => 1,
			'title' => 'First Article',
			'body' => 'First Article Body',
			'author_id' => 1,
			'published' => 'Y',
		];
		$this->assertEquals($expected, $first->articles[0]->toArray());
	}

/**
 * Tests that belongsTo relations are correctly hydrated into a custom entity class
 *
 * @return void
 */
	public function testHydrateBelongsToCustomEntity() {
		$authorEntity = $this->getMockClass('\Cake\ORM\Entity', ['foo']);
		$table = Table::build('article', ['table' => 'articles']);
		Table::build('author', [
			'connection' => $this->connection,
			'entityClass' => '\\' . $authorEntity
		]);
		$table->belongsTo('author');

		$query = new Query($this->connection, $table);
		$results = $query->select()
			->contain('author')
			->order(['article.id' => 'asc'])
			->toArray();

		$first = $results[0];
		$this->assertInstanceOf($authorEntity, $first->author);
	}
}