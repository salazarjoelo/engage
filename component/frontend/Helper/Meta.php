<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2020 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Engage\Site\Helper;

use Akeeba\Engage\Admin\Model\Comments;
use Exception;
use FOF30\Container\Container;
use Joomla\CMS\Date\Date;
use Joomla\Registry\Registry;

defined('_JEXEC') or die();

final class Meta
{
	/**
	 * A temporary instance of the component's container
	 *
	 * @var  Container|null
	 */
	private static $container = null;

	/**
	 * Cached results of resource metadata per asset ID
	 *
	 * @var  array
	 */
	private static $cachedMeta = [];

	/**
	 * Number of comments per asset ID
	 *
	 * @var  int[]
	 */
	private static $numCommentsPerAsset = [];

	/**
	 * IDs of comments per asset in the same order they are paginated in the front-end
	 *
	 * @var  array
	 */
	private static $commentIDsPerAsset = [];

	/**
	 * Returns the metadata of an asset.
	 *
	 * This method goes through the onAkeebaEngageGetAssetMeta plugin event, allowing different plugins to return
	 * information about the asset IDs they recognize. The results are cached to avoid expensive roundtrips to the
	 * Joomla plugin event system and the database.
	 *
	 * The returned keys are:
	 *
	 * * `type`: resource type
	 * * `title`: display title
	 * * `category`: display title for the category / parent item of the resource
	 * * `url`: canonical (frontend) or edit (backend) link for the resource; null if not applicable
	 * * `published`: is the asset published?
	 * * `access`: access level for the resource (e.g. article) this asset ID corresponds to; null if it doesn't apply.
	 * * `parent_access`: access level for the resource's parent (e.g. article category); null if it doesn't apply.
	 *
	 * @param   int  $assetId
	 *
	 * @return array
	 */
	public static function getAssetAccessMeta(int $assetId = 0, bool $loadParameters = false): array
	{
		$metaKey    = md5($assetId . '_' . ($loadParameters ? 'with' : 'without') . '_parameters');
		$altMetaKey = md5($assetId . '_with_parameters');

		if (array_key_exists($metaKey, self::$cachedMeta))
		{
			return self::$cachedMeta[$metaKey];
		}

		if (array_key_exists($altMetaKey, self::$cachedMeta))
		{
			return self::$cachedMeta[$altMetaKey];
		}

		self::$cachedMeta[$metaKey] = [
			'type'          => 'unknown',
			'title'         => '',
			'category'      => null,
			'author_name'   => null,
			'author_email'  => null,
			'url'           => null,
			'public_url'    => null,
			'published'     => false,
			'published_on'  => new Date(),
			'access'        => 0,
			'parent_access' => null,
			'parameters'    => new Registry(),
		];

		$container = Container::getInstance('com_engage');
		$platform  = $container->platform;

		$platform->importPlugin('content');
		$pluginResults = $platform->runPlugins('onAkeebaEngageGetAssetMeta', [$assetId, $loadParameters]);

		$pluginResults = array_filter($pluginResults, function ($x) {
			return is_array($x);
		});

		if (empty($pluginResults))
		{
			return self::$cachedMeta[$metaKey];
		}

		$tempRet = array_shift($pluginResults);

		foreach (self::$cachedMeta[$metaKey] as $k => $v)
		{
			if (!array_key_exists($k, $tempRet))
			{
				continue;
			}

			self::$cachedMeta[$metaKey][$k] = $tempRet[$k] ?? $v;
		}

		return self::$cachedMeta[$metaKey];
	}

	/**
	 * Are the comments closed for the specified resource?
	 *
	 * @param   int  $assetId  The asset ID of the resource
	 *
	 * @return  bool
	 */
	public static function areCommentsClosed(int $assetId = 0)
	{
		$meta = self::getAssetAccessMeta($assetId, true);

		/** @var Registry $params */
		$params = $meta['parameters'];

		if ($params->get('comments_enabled', 0) != 1)
		{
			return true;
		}

		$closeAfter = $params->get('comments_close_after', 0);

		if ($closeAfter <= 0)
		{
			return false;
		}

		// Check if the comments are auto-closed.
		/** @var Date $date */
		$date = $meta['published_on'];

		try
		{
			return ($date->add(new \DateInterval(sprintf('P%dD', $closeAfter)))->toUnix() <= time());
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	/**
	 * Returns the total number of comments for a specific asset ID
	 *
	 * @param   int  $asset_id  The asset ID to check for the total number of comments
	 *
	 * @return  int
	 */
	public static function getNumCommentsForAsset(int $asset_id): int
	{
		if (isset(self::$numCommentsPerAsset[$asset_id]))
		{
			return self::$numCommentsPerAsset[$asset_id];
		}

		/** @var Comments $model */
		$model                                = self::getContainer()->factory->model('Comments')->tmpInstance();
		self::$numCommentsPerAsset[$asset_id] = $model->asset_id($asset_id)->count() ?? 0;

		return self::$numCommentsPerAsset[$asset_id];
	}

	/**
	 * Returns the comments IDs for an asset, in the same order as they are paginated in the frontend
	 *
	 * @param   int   $asset_id   Asset ID
	 * @param   bool  $asManager  True to take into account unpublished comments
	 *
	 * @return  array
	 */
	public static function getPaginatedCommentIDsForAsset(int $asset_id, bool $asManager = false): array
	{
		if (isset(self::$commentIDsPerAsset[$asset_id]))
		{
			return self::$commentIDsPerAsset[$asset_id];
		}

		/** @var Comments $model */
		$model = self::getContainer()->factory->model('Comments')->tmpInstance();
		$model->scopeAssetCommentTree($asset_id);
		$model->asset_id($asset_id);

		if (!$asManager)
		{
			$model->enabled(1);
		}

		$query = $model->buildQuery(true);
		$query->clear('select')->select($query->qn('node.engage_comment_id'));

		self::$commentIDsPerAsset[$asset_id] = self::getContainer()->db->setQuery($query)->loadColumn() ?? [];

		return self::$commentIDsPerAsset[$asset_id];
	}

	/**
	 * Returns the limitstart required to reach a specific comment in the frontend comments display.
	 *
	 * @param   Comments  $comment          The comment we're looking for
	 * @param   int|null  $commentsPerPage  Number of comments per page. NULL to use global configuration.
	 * @param   bool      $asManager        True to take into account unpublished / spam comments.
	 *
	 * @return  int
	 */
	public static function getLimitStartForComment(Comments $comment, ?int $commentsPerPage = null, bool $asManager = false): int
	{
		// No limit set. Use the configured list limit, must be at least 5.
		if (is_null($commentsPerPage))
		{
			$commentsPerPage = self::getContainer()->platform->getConfig()->get('list_limit', 20);
			$commentsPerPage = max((int) $commentsPerPage, 5);
		}

		$comments = self::getPaginatedCommentIDsForAsset($comment->asset_id, $asManager);
		$index    = array_search($comment->getId(), $comments);

		if ($index === false)
		{
			return 0;
		}

		return intdiv($index, $commentsPerPage) * $commentsPerPage;
	}

	/**
	 * Returns the component's container (temporary instance)
	 *
	 * @return  Container|null
	 */
	private static function getContainer(): Container
	{
		if (is_null(self::$container))
		{
			self::$container = Container::getInstance('com_engage', [
				'tempInstance' => true,
			]);
		}

		return self::$container;
	}
}