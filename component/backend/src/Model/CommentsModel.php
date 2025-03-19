<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\Timer;
use Akeeba\Component\Engage\Administrator\Mixin\ModelPopulateStateTrait;
use DateInterval;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;

/**
 * Backend comments list model
 *
 * @since 3.0.0
 */
#[\AllowDynamicProperties]
class CommentsModel extends ListModel
{
	use ModelPopulateStateTrait;

	private const CONJOINED = 1;

	/**
	 * The number of tree-aware comments fetched by commentIDTreeSliceWithDepth
	 *
	 * @var   int
	 * @since 1.0.0
	 * @see   self::commentIDTreeSliceWithDepth
	 */
	private $treeAwareCount = null;

	/**
	 * Constructor
	 *
	 * @param   array                $config   An array of configuration options (name, state, dbo, table_path,
	 *                                         ignore_request).
	 * @param   MVCFactoryInterface  $factory  The factory.
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	public function __construct($config = [], MVCFactoryInterface $factory = null)
	{
		$config['filter_fields'] = $config['filter_fields'] ?? [
			// Sortable and/or filter columns
			'id',
			'asset_id',
			'name',
			'email',
			'ip',
			'user_agent',
			'enabled',
			'created',
			'created_by',
			'modified',
			'modified_by',
			'categories_include',
			'categories_exclude',
			// Sort–only fields
			'c.id',
			'user_name',
			'c.enabled',
			'c.created',
			// Filter–only fields
			'search',
			'from',
			'to',
		];

		parent::__construct($config, $factory);

		$this->setupStateFilters(
			[
				// Visible filters
				'search'             => 'string',
				'from'               => 'string',
				'to'                 => 'string',
				'created_by'         => 'int',
				'enabled'            => 'int',

				// Internal filters
				'asset_id'           => 'int',
				'parent_id'          => 'int',
				'frontend'           => 'int',
				'categories_include' => 'array',
				'categories_exclude' => 'array',
			], 'c.created', 'DESC'
		);
	}

	/**
	 * Get the number of tree–aware comments fetched by commentIDTreeSliceWithDepth
	 *
	 * @return   int
	 * @since    1.0.0
	 */
	public function getTreeAwareCount(): int
	{
		if (is_null($this->treeAwareCount))
		{
			$this->commentIDTreeSliceWithDepth(0);
		}

		return $this->treeAwareCount ?? 0;
	}

	/**
	 * Tree-aware version of getItems(), returning a slice of the tree.
	 *
	 * @param   int|null  $start  Starting offset
	 * @param   int|null  $limit  Max number of items to retrieve
	 *
	 * @return  array
	 * @since   1.0.0
	 * @see     self::get
	 */
	public function commentTreeSlice(?int $start = null, ?int $limit = null): array
	{
		$start = $start ?? $this->getStart();
		$limit = $limit ?? $this->getState('list.limit');

		// Get a slice of comment IDs and their depth in tree listing order
		$idsAndDepth = $this->commentIDTreeSliceWithDepth($start, $limit);

		// No IDs? No items!
		if (empty($idsAndDepth))
		{
			return [];
		}

		// Get the comments with the IDs specified. They are NOT in order.
		$db    = $this->getDatabase();
		$query = $this->getListQuery()
			->whereIn($db->quoteName('c.id'), array_map('trim', array_keys($idsAndDepth)))
			->clear('order');
		$items = $db->setQuery($query)->loadObjectList('id');

		// Create a new collection
		$ret = [];

		/**
		 * Distribute the items to the collection in the order they SHOULD appear.
		 *
		 * Magic trick: since the collection internally has an array consisting entirely of objects, creating a second
		 * collection referencing the same objects has minimal overhead. The reason is that objects are stored in arrays
		 * as references. Adding the same object to two arrays only adds its reference to the array, without copying the
		 * actual object. This helps keep memory pressure low while we are rearranging our items in an arbitrary order.
		 * Neat, huh?
		 */
		foreach ($idsAndDepth as $id => $depth)
		{
			$id = (int) $id;

			if (!isset($items[$id]))
			{
				continue;
			}

			// When adding the item to the collection we also need to set its level information.
			$item        = $items[$id];
			$item->depth = $depth;
			$ret[]       = $item;
		}

		return $ret;
	}

	/**
	 * Automatically deletes obsolete spam comments older than this many days, using an upper execution time limit.
	 *
	 * If the $maxDays == 0 nothing is deleted; we return without querying the database.
	 *
	 * If there are numerous spam comments this method will delete at least one chunk (100 comments). It will keep on
	 * going until the maxExecutionTime limit is reached or exceeded; or until there are no more spam comments left to
	 * delete.
	 *
	 * Use $maxExecutionTime=0 to only delete up to 100 comments.
	 *
	 * @param   int  $maxDays           Spam older than this many days will be automatically deleted
	 * @param   int  $maxExecutionTime  Maximum time to spend cleaning obsolete spam
	 *
	 * @return  int  Total number of spam comments deleted.
	 * @since   1.0.0
	 */
	public function cleanSpam(int $maxDays = 15, int $maxExecutionTime = 1): int
	{
		$timer   = new Timer($maxExecutionTime, 100);
		$deleted = 0;

		do
		{
			$deletedNow = $this->cleanSpamChunk($maxDays);
			$deleted    += $deletedNow;

			if ($deletedNow === 0)
			{
				break;
			}
		} while ($timer->getTimeLeft() > 0.01);

		return $deleted;
	}

	/**
	 * Get a slice of comment IDs with depth (level) information.
	 *
	 * The comment ID slice is aware of the tree nature of the comments.
	 *
	 * Use $start=0 and $limit=null to retrieve the entire tree
	 *
	 * @param   int       $start  Starting offset of the slice
	 * @param   int|null  $limit  Maximum number of elements to retrieve
	 *
	 * @return  array  An array of id => depth
	 * @since   1.0.0
	 */
	public function commentIDTreeSliceWithDepth(int $start, ?int $limit = null): array
	{
		// Get all the IDs filtered by the model
		$db     = $this->getDatabase();
		$query  = $this->getListQuery(true)
			->clear('select')
			->select(
				[
					$db->qn('c.id'),
					$db->qn('c.parent_id'),
				]
			);
		$allIDs = $db->setQuery($query)->loadAssocList('id') ?? [];

		$this->treeAwareCount = 0;

		// No IDs? Empty list!
		if (empty($allIDs))
		{
			return [];
		}

		// Convert into an ID => parent array
		$allIDs = array_map(
			function ($x) {
				return $x['parent_id'] ?: null;
			}, $allIDs
		);

		$this->treeAwareCount = count($allIDs);

		// Filter out orphan nodes (children of deleted or unpublished comments)
		$allIDs = array_filter(
			$allIDs, function ($parent_id) use ($allIDs) {
			return is_null($parent_id) || array_key_exists($parent_id, $allIDs);
		}
		);

		/**
		 * Create a tree version of the comments and flatten it out
		 *
		 * Starting at parent id NULL forces makeIDTree to start from the first level nodes that have no parents.
		 */
		$flattened = $this->flattenIDTree($this->makeIDTree($allIDs, null));

		unset($allIDs);

		if ($limit > 0)
		{
			return array_slice($flattened, $start, $limit, true);
		}

		return array_slice($flattened, $start, null, true);
	}

	public function clearCache($id = '')
	{
		unset($this->cache[$this->getStoreId($id)]);
	}

	/**
	 * Get the latest commented articles, with their corresponding latest comment's information.
	 *
	 * @param   int  $numArticles  Up to how many articles to return
	 *
	 * @return  array
	 * @since   3.4.0
	 */
	public function getLatestArticles(int $numArticles = 10): array
	{
		$db = $this->getDatabase();
		/** @var DatabaseQuery $query */
		$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true));
		$query->select('DISTINCT ' . $db->quoteName('c.asset_id'))
			->from($db->quoteName('#__engage_comments', 'c'))
			->join(
				'LEFT', $db->quoteName('#__content', 'a'),
				$db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id')
			)
			->order($db->quoteName('c.created') . ' DESC')
			->setLimit($numArticles, 0);

		// Include Categories Filter
		$fltCatInclude = $this->getState('filter.categories_include', []);

		if (is_array($fltCatInclude) && !empty($fltCatInclude))
		{
			$query->whereIn($db->quoteName('a.catid'), $fltCatInclude);
		}

		// Exclude Categories Filter
		$fltCatExclude = $this->getState('filter.categories_exclude', []);

		if (is_array($fltCatExclude) && !empty($fltCatExclude))
		{
			$query->whereNotIn($db->quoteName('a.catid'), $fltCatExclude);
		}

		try
		{
			$assetIds = $db->setQuery($query)->loadColumn();
		}
		catch (Exception $e)
		{
			return [];
		}

		if (empty($assetIds))
		{
			return [];
		}

		$stashAssetId = $this->getState('filter.asset_id');
		$stashStart   = $this->getState('list.start');
		$stashLimit   = $this->getState('list.limit');

		$ret = [];

		foreach ($assetIds as $assetId)
		{
			$this->setState('filter.asset_id', $assetId);
			$this->setState('list.start', 0);
			$this->setState('list.limit', 1);

			$ret = array_merge($ret, $this->getItems() ?: []);
		}

		$this->setState('filter.asset_id', $stashAssetId);
		$this->setState('list.start', $stashStart);
		$this->setState('list.limit', $stashLimit);

		return $ret;
	}

	/**
	 * Utility function that converts an array of id => parent_id into a tree representation of IDs.
	 *
	 * @param   array     $allIDs    The source array of id => parent_id entries
	 * @param   int|null  $parentId  The parent ID to retrieve
	 *
	 * @return  array
	 * @since   1.0.0
	 * @see     self::commentIDTreeSliceWithDepth
	 */
	protected function makeIDTree(array &$allIDs, ?int $parentId): array
	{
		$childIDs = array_keys($allIDs, $parentId);

		if (empty($childIDs))
		{
			return [];
		}

		$ret = [];

		foreach ($childIDs as $thisParentId)
		{
			$ret[$thisParentId] = $this->makeIDTree($allIDs, $thisParentId);
		}

		return $ret;
	}

	/**
	 * Converts a tree of IDs into a flat array of ID => depth preserving ID order as seen in the tree.
	 *
	 * @param   array  $tree         The tree array
	 * @param   int    $parentLevel  Which level am I currently in
	 *
	 * @return  array
	 * @since   1.0.0
	 * @see     self::commentIDTreeSliceWithDepth
	 * @see     self::makeIDTree
	 */
	protected function flattenIDTree(array $tree, $parentLevel = 0): array
	{
		$ret = [];

		foreach ($tree as $k => $v)
		{
			$ret[" " . $k] = $parentLevel + 1;

			if (!empty($v) && is_array($v))
			{
				$ret = array_merge($ret, $this->flattenIDTree($v, $parentLevel + 1));
			}
		}

		return $ret;
	}

	/**
	 * Get a DatabaseQuery object for retrieving the data from the database table.
	 *
	 * @return  DatabaseQuery  A DatabaseQuery object to retrieve the data.
	 *
	 * @since   3.0
	 */
	protected function getListQuery()
	{
		$db    = $this->getDatabase();
		$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select(
				[
					$db->quoteName('c') . '.*',
					'IFNULL(' . $db->quoteName('u.name') . ', ' . $db->quoteName('c.name') . ') AS ' . $db->quoteName(
						'user_name'
					),
					'IFNULL(' . $db->quoteName('u.email') . ', ' . $db->quoteName('c.email') . ') AS ' . $db->quoteName(
						'user_email'
					),
					$db->quoteName('a.title', 'article_title'),
					$db->quoteName('a.alias', 'article_alias'),
					$db->quoteName('a.catid', 'article_catid'),
					$db->quoteName('cat.title', 'cat_title'),
					$db->quoteName('cat.alias', 'cat_alias'),
				]
			)
			->from($db->quoteName('#__engage_comments', 'c'));

		if (!self::CONJOINED)
		{
			$query
				->join(
					'LEFT', $db->quoteName('#__users', 'u'),
					$db->quoteName('u.id') . ' = ' . $db->quoteName('c.created_by')
				)
				->join(
					'LEFT', $db->quoteName('#__content', 'a'),
					$db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id')
				)
				->join(
					'LEFT', $db->quoteName('#__categories', 'cat'),
					$db->quoteName('cat.id') . ' = ' . $db->quoteName('a.catid')
				);
		}
		else
		{
			$conjoinedWhere = [
				$db->quoteName('u.id') . ' = ' . $db->quoteName('c.created_by'),
				$db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id'),
				$db->quoteName('cat.id') . ' = ' . $db->quoteName('a.catid'),
			];
		}

		// Frontend listing filter
		$fltFrontend = $this->getState('filter.frontend');

		if ($fltFrontend === 1)
		{
			$user       = Factory::getApplication()->getIdentity() ?? (new User());
			$userAccess = $user->getAuthorisedViewLevels() ?: [];
			$query
				->select(
					[
						$db->quoteName('a.id', 'article_id'),
						$db->quoteName('cat.id', 'cat_id'),
					]
				)
				->where($db->quoteName('cat.published') . ' = 1')
				->where($db->quoteName('a.state') . ' = 1')
				->whereIn($db->quoteName('a.access'), $userAccess, ParameterType::INTEGER)
				->whereIn($db->quoteName('cat.access'), $userAccess, ParameterType::INTEGER);
		}

		// Include Categories Filter
		$fltCatInclude = $this->getState('filter.categories_include', []);

		if (is_array($fltCatInclude) && !empty($fltCatInclude))
		{
			$query->whereIn($db->quoteName('cat.id'), $fltCatInclude);
		}

		// Exclude Categories Filter
		$fltCatExclude = $this->getState('filter.categories_exclude', []);

		if (is_array($fltCatExclude) && !empty($fltCatExclude))
		{
			$query->whereNotIn($db->quoteName('cat.id'), $fltCatExclude);
		}

		// Asset ID filter
		$fltAssetId = $this->getState('filter.asset_id');

		if (is_numeric($fltAssetId) && ($fltAssetId > 0))
		{
			$query->where($db->quoteName('c.asset_id') . ' = :asset_id')
				->bind(':asset_id', $fltAssetId, ParameterType::INTEGER);
		}

		// Parent ID filter
		$fltParentid = $this->getState('filter.parent_id');

		if (is_numeric($fltParentid) && ($fltParentid > 0))
		{
			$query->where($db->quoteName('c.parent_id') . ' = :parent_id')
				->bind(':parent_id', $fltParentid, ParameterType::INTEGER);
		}

		// Search filter
		$fltSearch    = $this->getState('filter.search');
		$fltCreatedBy = $this->getState('filter.created_by');

		if (!empty($fltSearch))
		{
			if (substr($fltSearch, 0, 3) === 'id:')
			{
				$fsValue = @intval(trim(substr($fltSearch, 3)));

				if (is_int($fsValue) && ($fsValue > 0))
				{
					$query->where($db->quoteName('c.id') . ' = :filter_search')
						->bind(':filter_search', $fsValue, ParameterType::INTEGER);
				}
			}
			elseif (substr($fltSearch, 0, 3) === 'ip:')
			{
				$fsValue = trim(substr($fltSearch, 3));

				if ($fsValue)
				{
					$fsValue = '%' . substr($fltSearch, 3) . '%';

					$query->where($db->quoteName('c.ip') . ' LIKE :filter_search')
						->bind(':filter_search', $fsValue, ParameterType::STRING);
				}
			}
			elseif (substr($fltSearch, 0, 5) === 'user:')
			{
				$fsValue = trim(substr($fltSearch, 5));

				if ($fsValue)
				{
					$fltCreatedBy = null;
					$fsValue      = '%' . $fsValue . '%';

					/**
					 * Performance tweak.
					 *
					 * If we do not put these filters in the ON clause of the JOIN we get a very slow query. However,
					 * putting these filters _only_ in the ON clause isn't enough, because MySQL won't actually apply
					 * the filters. So, we have to put them in both places.
					 *
					 * Using EXPLAIN ANALYZE tells us why this matters.
					 *
					 * In both cases, the innermost nested loop inner join loops for the number of comment rows.
					 *
					 * Without the filter, each iteration loops for the total number of user rows.
					 *
					 * With the filter, the filter is evaluated first, so each iteration only loops for the number of
					 * matched rows.
					 *
					 * Since the number of matched rows is significantly smaller than the total number of table rows,
					 * the innermost loop completes faster.
					 *
					 * Note that in both cases the innermost loop calculates the exact same matrix product of two
					 * tables (same number of rows). This will still have to be filtered by the WHERE clause. The trick
					 * is that by placing the filter in the ON clause we arrive at the product much faster, thus saving
					 * a lot of time.
					 *
					 * TODO Can I make the matrix multiplication less asinine?!
					 */
					if (self::CONJOINED)
					{
						$conjoinedWhere[] = '(' . implode(
								' OR ',
								[
									$db->quoteName('u.name') . ' LIKE :fsv1',
									$db->quoteName('u.email') . ' LIKE :fsv2',
									$db->quoteName('c.name') . ' LIKE :fsv3',
									$db->quoteName('c.email') . ' LIKE :fsv4',
								]
							) . ')';
						$query
							->bind(':fsv1', $fsValue, ParameterType::STRING)
							->bind(':fsv2', $fsValue, ParameterType::STRING)
							->bind(':fsv3', $fsValue, ParameterType::STRING)
							->bind(':fsv4', $fsValue, ParameterType::STRING);
					}

					if ($query->where === null)
					{
						// So that extendWhere() doesn't output bad SQL
						$query->where('1=1');
					}

					$query->extendWhere(
						'AND',
						[
							$db->quoteName('u.name') . ' LIKE :filter_search_1',
							$db->quoteName('u.email') . ' LIKE :filter_search_3',
							$db->quoteName('c.name') . ' LIKE :filter_search_2',
							$db->quoteName('c.email') . ' LIKE :filter_search_4',
						],
						'OR'
					)
						->bind(':filter_search_1', $fsValue, ParameterType::STRING)
						->bind(':filter_search_3', $fsValue, ParameterType::STRING)
						->bind(':filter_search_2', $fsValue, ParameterType::STRING)
						->bind(':filter_search_4', $fsValue, ParameterType::STRING);
				}
			}
			elseif (substr($fltSearch, 0, 9) === 'username:')
			{
				$fsValue = '%' . substr($fltSearch, 9) . '%';

				$query->where($db->quoteName('u.username') . ' LIKE :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::STRING);
			}
			elseif (substr($fltSearch, 0, 6) === 'title:')
			{
				$fsValue = '%' . substr($fltSearch, 6) . '%';

				$query->where($db->quoteName('a.title') . ' = :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::STRING);
			}
			elseif (substr($fltSearch, 0, 8) === 'comment:')
			{
				$fsValue = '%' . substr($fltSearch, 8) . '%';

				$query->where($db->quoteName('c.body') . 'LIKE :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::STRING);
			}
			else
			{
				$fsValue = '%' . $fltSearch . '%';

				if ($query->where === null)
				{
					// So that extendWhere() doesn't output bad SQL
					$query->where('1=1');
				}

				$query->extendWhere(
					'AND', [
					$db->quoteName('c.body') . 'LIKE :filter_search',
					$db->quoteName('u.name') . ' LIKE :filter_search_1',
					$db->quoteName('c.name') . ' LIKE :filter_search_2',
					$db->quoteName('u.email') . ' LIKE :filter_search_3',
					$db->quoteName('c.email') . ' LIKE :filter_search_4',
				], 'OR'
				)
					->bind(':filter_search', $fsValue, ParameterType::STRING)
					->bind(':filter_search_1', $fsValue, ParameterType::STRING)
					->bind(':filter_search_2', $fsValue, ParameterType::STRING)
					->bind(':filter_search_3', $fsValue, ParameterType::STRING)
					->bind(':filter_search_4', $fsValue, ParameterType::STRING);
			}
		}

		// Created By filter
		if (is_numeric($fltCreatedBy) && ($fltCreatedBy > 0))
		{
			$query->where($db->quoteName('c.created_by') . ' = :created_by')
				->bind(':created_by', $fltCreatedBy, ParameterType::INTEGER);
		}

		// Enabled filter
		$fltEnabled = $this->getState('filter.enabled');

		if (is_numeric($fltEnabled))
		{
			$query->where($db->quoteName('c.enabled') . ' = :enabled')
				->bind(':enabled', $fltEnabled, ParameterType::INTEGER);
		}

		// From/to filter
		$fltFrom = $this->getState('filter.from');
		$fltTo   = $this->getState('filter.to');

		// -- Convert to Joomla date objects
		try
		{
			$fltFrom = $fltFrom ? Factory::getDate($fltFrom) : null;
		}
		catch (Exception $e)
		{
			$fltFrom = null;
		}

		try
		{
			$fltTo = $fltFrom ? Factory::getDate($fltTo) : null;
		}
		catch (Exception $e)
		{
			$fltTo = null;
		}

		// Swap dates if both are defined but from is later than to.
		if (!empty($fltTo) && !empty($fltFrom) && ($fltTo->diff($fltFrom) != 0))
		{
			$temp    = $fltFrom;
			$fltFrom = $fltTo;
			$fltTo   = $temp;
			unset($temp);
		}

		if (!empty($fltTo) && !empty($fltFrom))
		{
			$sFrom = $fltFrom->toSql();
			$sTo   = $fltTo->toSql();
			$query->where($db->quoteName('c.created_on') . ' BETWEEN :from AND :to')
				->bind(':from', $sFrom, ParameterType::STRING)
				->bind(':to', $sTo, ParameterType::STRING);
		}
		elseif (!empty($fltFrom))
		{
			$sFrom = $fltFrom->toSql();
			$query->where($db->quoteName('c.created_on') . ' >= :from')
				->bind(':from', $sFrom, ParameterType::STRING);
		}
		elseif (!empty($fltTo))
		{
			$sTo = $fltTo->toSql();
			$query->where($db->quoteName('c.created_on') . ' <= :to')
				->bind(':to', $sTo, ParameterType::STRING);
		}

		if (self::CONJOINED)
		{
			$conjoinedTables = [
				$db->quoteName('#__users', 'u') . ' USE INDEX (' . implode(
					',', array_map([$db, 'quoteName'], ['PRIMARY', 'idx_username', 'idx_name'])
				)
				. ')',
				$db->quoteName('#__content', 'a') . ' USE INDEX (' . implode(
					',', array_map([$db, 'quoteName'], ['PRIMARY', 'idx_catid'])
				)
				. ')',
				$db->quoteName('#__categories', 'cat') . ' USE INDEX (' . implode(
					',', array_map([$db, 'quoteName'], ['PRIMARY'])
				)
				. ')',
			];

			$query
				->join(
					'LEFT',
					'(' . implode(',', $conjoinedTables) . ')',
					implode(' AND ', $conjoinedWhere)
				);
		}

		// List ordering clause
		$orderCol  = $this->state->get('list.ordering', 'c.created');
		$orderDirn = $this->state->get('list.direction', 'DESC');
		$ordering  = $db->quoteName($orderCol) . ' ' . $db->escape($orderDirn);

		/**
		 * -- When ordering by a column other that the comment ID apply an additional ordering to make sure that the
		 *    comments always appear in the same order. Otherwise if there are two or more comments filed on the same
		 *    date and time (to the second) and we're sorting by date they would appear in a different order every time
		 *    we load the page. Same for the user_name and enabled status.
		 */
		if ($orderCol != 'c.id')
		{
			$ordering .= ', ' . $db->quoteName('c.id') . ' DESC';
		}

		$query->order($ordering);

		return $query;
	}

	/**
	 * Returns a record count for the query.
	 *
	 * Note: Current implementation of this method assumes that getListQuery() returns a set of unique rows,
	 * thus it uses SELECT COUNT(*) to count the rows. In cases that getListQuery() uses DISTINCT
	 * then either this method must be overridden by a custom implementation at the derived Model Class
	 * or a GROUP BY clause should be used to make the set unique.
	 *
	 * @param   DatabaseQuery|string  $query  The query.
	 *
	 * @return  integer  Number of rows for query.
	 *
	 * @since   3.0
	 */
	protected function _getListCount($query)
	{
		// Eliminate the JOINs if we're counting without applying filters to tables other than `#__engage_comments`.
		if (self::CONJOINED)
		{
			$hasOtherTables =
				$query->where !== null
				&& array_reduce(
					$query->where->getElements(),
					function (bool $carry, ?string $item) {
						if ($carry || empty($item))
						{
							return $carry;
						}

						[$t,] = explode('.', $item, 2);

						return $t !== '`c`';
					},
					false
				);

			if (!$hasOtherTables)
			{
				$query->clear('join');
			}
		}

		return parent::_getListCount($query);
	}

	protected function getStoreId($id = '')
	{
		$id .= ':' . $this->getState('filter.search');
		$id .= ':' . $this->getState('filter.from');
		$id .= ':' . $this->getState('filter.to');
		$id .= ':' . $this->getState('filter.created_by');
		$id .= ':' . $this->getState('filter.enabled');
		$id .= ':' . $this->getState('filter.asset_id');
		$id .= ':' . $this->getState('filter.parent_id');
		$id .= ':' . $this->getState('filter.frontend');
		$id .= ':' . serialize($this->getState('filter.categories_include'));
		$id .= ':' . serialize($this->getState('filter.categories_exclude'));

		return parent::getStoreId($id);
	}

	/**
	 * Automatically deletes up to 100 spam comments which are older than this many days.
	 *
	 * @param   int  $maxDays
	 *
	 * @return  int  Number of spam comments deleted
	 * @since   1.0.0
	 */
	private function cleanSpamChunk(int $maxDays = 15): int
	{
		$maxDays = max(0, $maxDays);

		if ($maxDays === 0)
		{
			return 0;
		}

		try
		{
			$interval     = new DateInterval(sprintf('P%uD', $maxDays));
			$earliestDate = (clone Factory::getDate())->sub($interval);
		}
		catch (Exception $e)
		{
			return 0;
		}

		/** @var self $model */
		$model = $this->getMVCFactory()->createModel(
			'Comments', 'Administrator', [
				'ignore_request' => true,
			]
		);
		$model->setState('filter.enabled', -3);
		$model->setState('filter.to', $earliestDate->toISO8601());
		$obsoleteSpam = $model->getItems();

		if (empty($obsoleteSpam))
		{
			return 0;
		}

		$spamIds = array_map(
			function ($x) {
				return $x->id;
			}, $obsoleteSpam
		);
		$spamIds = array_unique($spamIds);

		if (empty($spamIds))
		{
			return 0;
		}

		/** @var CommentModel $commentModel */
		$commentModel = $this->getMVCFactory()->createModel(
			'Comment', 'Administrator', [
				'ignore_request' => true,
			]
		);

		if (!$commentModel->delete($spamIds))
		{
			throw new \RuntimeException($commentModel->getError());
		}

		return count($spamIds);
	}
}
