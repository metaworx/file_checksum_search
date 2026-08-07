<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit;

use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Shared base class for FCIAS unit tests.
 *
 * Provides a pre-wired QueryBuilder mock with default stubs for all
 * chainable methods.  Tests only override methods that need specific
 * return values (e.g. executeQuery → IResult mock) or explicit
 * call-count assertions.
 *
 * Usage:
 *   protected function setUp(): void {
 *       parent::setUp();
 *       $this->db = $this->createMock(IDBConnection::class);
 *       $this->setUpQueryBuilderMock();
 *       // … create service under test …
 *   }
 */
abstract class FciasUnitTestCase
	extends
	TestCase
{

	protected IDBConnection&MockObject      $db;

	protected IQueryBuilder&MockObject      $queryBuilder;

	protected IExpressionBuilder&MockObject $expr;

	protected IFunctionBuilder&MockObject   $func;


	/**
	 * Wire the shared QueryBuilder mock chain and set default stubs
	 * on every commonly-used chainable method.
	 *
	 * Call this AFTER setting $this->db in the child setUp().
	 */
	protected function setUpQueryBuilderMock(): void
	{

		$this->queryBuilder = $this->createMock( IQueryBuilder::class );
		$this->expr         = $this->createMock( IExpressionBuilder::class );
		$this->func         = $this->createMock( IFunctionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $this->queryBuilder )
		;

		// --- QueryBuilder: structural methods (always return self) ---

		$this->queryBuilder->method( 'select' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'selectAlias' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'from' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'innerJoin' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'leftJoin' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'rightJoin' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'andWhere' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'orWhere' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'setMaxResults' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'setFirstResult' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'orderBy' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'addOrderBy' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'groupBy' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'addGroupBy' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'having' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'andHaving' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'set' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'update' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'delete' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'insert' )
		                   ->willReturnSelf()
		;

		// --- QueryBuilder: expr / func / createFunction ---

		$this->queryBuilder->method( 'expr' )
		                   ->willReturn( $this->expr )
		;

		$this->queryBuilder->method( 'func' )
		                   ->willReturn( $this->func )
		;

		$this->queryBuilder->method( 'createFunction' )
		                   ->willReturnCallback(
			                   fn ( string $sql ) => $sql,
		                   )
		;

		$this->queryBuilder->method( 'createNamedParameter' )
		                   ->willReturnCallback(
			                   fn ( $value, $type = null ) => $value,
		                   )
		;

		// --- ExpressionBuilder: common comparisons (return dummy SQL) ---

		$this->expr->method( 'eq' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'neq' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'lt' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'lte' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'gt' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'gte' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'like' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'in' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'notIn' )
		           ->willReturn( '1=1' )
		;
	}

}
