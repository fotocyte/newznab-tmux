<?php

namespace Blacklight;

use App\Models\Release;
use App\Models\Category;
use App\Models\Settings;
use Chumper\Zipper\Zipper;
use App\Models\UsenetGroup;
use Illuminate\Support\Arr;
use Blacklight\utility\Utility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

/**
 * Class Releases.
 */
class Releases
{
    // RAR/ZIP Passworded indicator.
    public const PASSWD_NONE = 0; // No password.
    public const PASSWD_POTENTIAL = 1; // Might have a password.
    public const BAD_FILE = 2; // Possibly broken RAR/ZIP.
    public const PASSWD_RAR = 10; // Definitely passworded.

    /**
     * @var \Blacklight\SphinxSearch
     */
    public $sphinxSearch;

    /**
     * @var int
     */
    public $passwordStatus;

    /**
     * @var array Class instances.
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        $defaults = [
            'Settings' => null,
            'Groups'   => null,
        ];
        $options += $defaults;

        $this->sphinxSearch = new SphinxSearch();
    }

    /**
     * Used for Browse results.
     *
     *
     * @param       $page
     * @param       $cat
     * @param       $start
     * @param       $num
     * @param       $orderBy
     * @param int   $maxAge
     * @param array $excludedCats
     * @param array $tags
     * @param int   $groupName
     * @param int   $minSize
     *
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function getBrowseRange($page, $cat, $start, $num, $orderBy, $maxAge = -1, array $excludedCats = [], $groupName = -1, $minSize = 0, array $tags = [])
    {
        $orderBy = $this->getBrowseOrder($orderBy);

        $qry = sprintf(
            "SELECT r.*, cp.title AS parent_category, c.title AS sub_category,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				CONCAT(cp.id, ',', c.id) AS category_ids,
				df.failed AS failed,
				rn.releases_id AS nfoid,
				re.releases_id AS reid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.title, tve.firstaired
			FROM
			(
				SELECT r.*, g.name AS group_name
				FROM releases r
				LEFT JOIN usenet_groups g ON g.id = r.groups_id
				%s
				WHERE r.nzbstatus = %d
				AND r.passwordstatus %s
				%s %s %s %s %s %s
				ORDER BY %s %s %s
			) r
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			GROUP BY r.id
			ORDER BY %10\$s %11\$s",
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            NZB::NZB_ADDED,
            $this->showPasswords(),
            ! empty($tags) ? " AND tt.tag_name IN ('".implode("','", $tags)."')" : '',
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? (' AND postdate > NOW() - INTERVAL '.$maxAge.' DAY ') : ''),
            (\count($excludedCats) ? (' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')') : ''),
            ((int) $groupName !== -1 ? sprintf(' AND g.name = %s ', escapeString($groupName)) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : ''),
            $orderBy[0],
            $orderBy[1],
            ($start === false ? '' : ' LIMIT '.$num.' OFFSET '.$start)
        );

        $releases = Cache::get(md5($qry.$page));
        if ($releases !== null) {
            return $releases;
        }
        $sql = Release::fromQuery($qry);
        if (\count($sql) > 0) {
            $possibleRows = $this->getBrowseCount($cat, $maxAge, $excludedCats, $groupName, $tags);
            $sql[0]->_totalcount = $sql[0]->_totalrows = $possibleRows;
        }
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($qry.$page), $sql, $expiresAt);

        return $sql;
    }

    /**
     * Used for pager on browse page.
     *
     * @param array      $cat
     * @param int        $maxAge
     * @param array      $excludedCats
     * @param string|int $groupName
     *
     * @param array      $tags
     *
     * @return int
     */
    public function getBrowseCount($cat, $maxAge = -1, array $excludedCats = [], $groupName = '', array $tags = []): int
    {
        $sql = sprintf(
            'SELECT COUNT(r.id) AS count
				FROM releases r
				%s %s
				WHERE r.nzbstatus = %d
				AND r.passwordstatus %s
				%s
				%s %s %s %s ',
            ($groupName !== -1 ? 'LEFT JOIN usenet_groups g ON g.id = r.groups_id' : ''),
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            NZB::NZB_ADDED,
            $this->showPasswords(),
            ($groupName !== -1 ? sprintf(' AND g.name = %s', escapeString($groupName)) : ''),
            ! empty($tags) ? ' AND tt.tag_name IN ('.escapeString(implode(',', $tags)).')' : '',
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? (' AND r.postdate > NOW() - INTERVAL '.$maxAge.' DAY ') : ''),
            (\count($excludedCats) ? (' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')') : '')
        );
        $count = Cache::get(md5($sql));
        if ($count !== null) {
            return $count;
        }
        $count = Release::fromQuery($sql);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_short'));
        Cache::put(md5($sql), $count[0]->count, $expiresAt);

        return $count[0]->count ?? 0;
    }

    /**
     * @return string
     */
    public function showPasswords()
    {
        $setting = (int) Settings::settingValue('..showpasswordedrelease');
        $setting = $setting ?? 10;
        switch ($setting) {
            case 0: // Hide releases with a password or a potential password (Hide unprocessed releases).

                    return '= '.self::PASSWD_NONE;
            case 1: // Show releases with no password or a potential password (Show unprocessed releases).

                    return '<= '.self::PASSWD_POTENTIAL;
            case 2: // Hide releases with a password or a potential password (Show unprocessed releases).
                    return '<= '.self::PASSWD_NONE;
            case 10: // Shows everything.
            default:
                    return '<= '.self::PASSWD_RAR;
        }
    }

    /**
     * Use to order releases on site.
     *
     * @param string|array $orderBy
     *
     * @return array
     */
    public function getBrowseOrder($orderBy): array
    {
        $orderArr = explode('_', ($orderBy === '' ? 'posted_desc' : $orderBy));
        switch ($orderArr[0]) {
            case 'cat':
                $orderField = 'categories_id';
                break;
            case 'name':
                $orderField = 'searchname';
                break;
            case 'size':
                $orderField = 'size';
                break;
            case 'files':
                $orderField = 'totalpart';
                break;
            case 'stats':
                $orderField = 'grabs';
                break;
            case 'posted':
            default:
                $orderField = 'postdate';
                break;
        }

        return [$orderField, isset($orderArr[1]) && preg_match('/^(asc|desc)$/i', $orderArr[1]) ? $orderArr[1] : 'desc'];
    }

    /**
     * Return ordering types usable on site.
     *
     * @return string[]
     */
    public function getBrowseOrdering(): array
    {
        return [
            'name_asc',
            'name_desc',
            'cat_asc',
            'cat_desc',
            'posted_asc',
            'posted_desc',
            'size_asc',
            'size_desc',
            'files_asc',
            'files_desc',
            'stats_asc',
            'stats_desc',
        ];
    }

    /**
     * Get list of releases available for export.
     *
     *
     * @param string $postFrom
     * @param string $postTo
     * @param string $groupID
     *
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection|static[]
     */
    public function getForExport($postFrom = '', $postTo = '', $groupID = '')
    {
        $query = Release::query()
            ->where('r.nzbstatus', NZB::NZB_ADDED)
            ->select(['r.searchname', 'r.guid', 'g.name as gname', DB::raw("CONCAT(cp.title,'_',c.title) AS catName")])
            ->from('releases as r')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('categories as cp', 'cp.id', '=', 'c.parentid')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'r.groups_id');

        if ($groupID !== '') {
            $query->where('r.groups_id', $groupID);
        }

        if ($postFrom !== '') {
            $dateParts = explode('/', $postFrom);
            if (\count($dateParts) === 3) {
                $query->where('r.postdate', '>', $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].'00:00:00');
            }
        }

        if ($postTo !== '') {
            $dateParts = explode('/', $postTo);
            if (\count($dateParts) === 3) {
                $query->where('r.postdate', '<', $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].'23:59:59');
            }
        }

        return $query->get();
    }

    /**
     * Get date in this format : 01/01/2014 of the oldest release.
     *
     * @note Used for exporting NZB's.
     * @return mixed
     */
    public function getEarliestUsenetPostDate()
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(min(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * Get date in this format : 01/01/2014 of the newest release.
     *
     * @note Used for exporting NZB's.
     * @return mixed
     */
    public function getLatestUsenetPostDate()
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(max(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * Gets all groups for drop down selection on NZB-Export web page.
     *
     * @param bool $blnIncludeAll
     *
     * @note Used for exporting NZB's.
     * @return array
     */
    public function getReleasedGroupsForSelect($blnIncludeAll = true): array
    {
        $groups = Release::query()
            ->selectRaw('DISTINCT g.id, g.name')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();
        $temp_array = [];

        if ($blnIncludeAll) {
            $temp_array[-1] = '--All Groups--';
        }

        foreach ($groups as $group) {
            $temp_array[$group['id']] = $group['name'];
        }

        return $temp_array;
    }

    /**
     * Cache of concatenated category ID's used in queries.
     * @var null|array
     */
    private $concatenatedCategoryIDsCache = null;

    /**
     * Gets / sets a string of concatenated category ID's used in queries.
     *
     * @return array|null|string
     */
    public function getConcatenatedCategoryIDs()
    {
        if ($this->concatenatedCategoryIDsCache === null) {
            $result = Category::query()
                ->remember(config('nntmux.cache_expiry_long'))
                ->whereNotNull('categories.parentid')
                ->whereNotNull('cp.id')
                ->selectRaw('CONCAT(cp.id, ", ", categories.id) AS category_ids')
                ->leftJoin('categories as cp', 'cp.id', '=', 'categories.parentid')
                ->get();
            if (isset($result[0]['category_ids'])) {
                $this->concatenatedCategoryIDsCache = $result[0]['category_ids'];
            }
        }

        return $this->concatenatedCategoryIDsCache;
    }

    /**
     * Get TV for My Shows page.
     *
     *
     * @param $userShows
     * @param $offset
     * @param $limit
     * @param $orderBy
     * @param int $maxAge
     * @param array $excludedCats
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function getShowsRange($userShows, $offset, $limit, $orderBy, $maxAge = -1, array $excludedCats = [])
    {
        $orderBy = $this->getBrowseOrder($orderBy);
        $sql = sprintf(
                "SELECT r.*,
					CONCAT(cp.title, '-', c.title) AS category_name,
					%s AS category_ids,
					usenet_groups.name AS group_name,
					rn.releases_id AS nfoid, re.releases_id AS reid,
					tve.firstaired,
					df.failed AS failed
				FROM releases r
				LEFT OUTER JOIN video_data re ON re.releases_id = r.id
				LEFT JOIN usenet_groups ON usenet_groups.id = r.groups_id
				LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
				LEFT OUTER JOIN tv_episodes tve ON tve.videos_id = r.videos_id
				LEFT JOIN categories c ON c.id = r.categories_id
				LEFT JOIN categories cp ON cp.id = c.parentid
				LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
				WHERE %s %s
				AND r.nzbstatus = %d
				AND r.categories_id BETWEEN %d AND %d
				AND r.passwordstatus %s
				%s
				GROUP BY r.id
				ORDER BY %s %s %s",
                $this->getConcatenatedCategoryIDs(),
                $this->uSQL($userShows, 'videos_id'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                Category::TV_ROOT,
                Category::TV_OTHER,
                $this->showPasswords(),
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : ''),
                $orderBy[0],
                $orderBy[1],
                ($offset === false ? '' : (' LIMIT '.$limit.' OFFSET '.$offset))
        );

        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        $result = Cache::get(md5($sql));
        if ($result !== null) {
            return $result;
        }

        $result = Release::fromQuery($sql);
        Cache::put(md5($sql), $result, $expiresAt);

        return $result;
    }

    /**
     * Get count for my shows page pagination.
     *
     * @param       $userShows
     * @param int   $maxAge
     * @param array $excludedCats
     *
     * @return int
     */
    public function getShowsCount($userShows, $maxAge = -1, array $excludedCats = []): int
    {
        return $this->getPagerCount(
            sprintf(
                'SELECT r.id
				FROM releases r
				WHERE %s %s
				AND r.nzbstatus = %d
				AND r.categories_id BETWEEN %d AND %d
				AND r.passwordstatus %s
				%s',
                $this->uSQL($userShows, 'videos_id'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                Category::TV_ROOT,
                Category::TV_OTHER,
                $this->showPasswords(),
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
            )
        );
    }

    /**
     * Delete multiple releases, or a single by ID.
     *
     * @param array|int|string $list   Array of GUID or ID of releases to delete.
     * @throws \Exception
     */
    public function deleteMultiple($list): void
    {
        $list = (array) $list;

        $nzb = new NZB();
        $releaseImage = new ReleaseImage();

        foreach ($list as $identifier) {
            $this->deleteSingle(['g' => $identifier, 'i' => false], $nzb, $releaseImage);
        }
    }

    /**
     * Deletes a single release by GUID, and all the corresponding files.
     *
     * @param array                    $identifiers ['g' => Release GUID(mandatory), 'id => ReleaseID(optional, pass
     *                                              false)]
     * @param \Blacklight\NZB          $nzb
     * @param \Blacklight\ReleaseImage $releaseImage
     *
     * @throws \Exception
     */
    public function deleteSingle($identifiers, NZB $nzb, ReleaseImage $releaseImage): void
    {
        // Delete NZB from disk.
        $nzbPath = $nzb->NZBPath($identifiers['g']);
        if (! empty($nzbPath)) {
            File::delete($nzbPath);
        }

        // Delete images.
        $releaseImage->delete($identifiers['g']);

        // Delete from sphinx.
        $this->sphinxSearch->deleteRelease($identifiers);

        // Delete from DB.
        Release::whereGuid($identifiers['g'])->delete();
    }

    /**
     * @param $guids
     * @param $category
     * @param $grabs
     * @param $videoId
     * @param $episodeId
     * @param $anidbId
     * @param $imdbId
     * @return bool|int
     */
    public function updateMulti($guids, $category, $grabs, $videoId, $episodeId, $anidbId, $imdbId)
    {
        if (! \is_array($guids) || \count($guids) < 1) {
            return false;
        }

        $update = [
            'categories_id'     => $category === -1 ? 'categories_id' : $category,
            'grabs'          => $grabs,
            'videos_id'      => $videoId,
            'tv_episodes_id' => $episodeId,
            'anidbid'        => $anidbId,
            'imdbid'         => $imdbId,
        ];

        return Release::query()->whereIn('guid', $guids)->update($update);
    }

    /**
     * Creates part of a query for some functions.
     *
     * @param array|\Illuminate\Database\Eloquent\Collection  $userQuery
     * @param string $type
     *
     * @return string
     */
    public function uSQL($userQuery, $type): string
    {
        $sql = '(1=2 ';
        foreach ($userQuery as $query) {
            $sql .= sprintf('OR (r.%s = %d', $type, $query->$type);
            if (! empty($query->categories)) {
                $catsArr = explode('|', $query->categories);
                if (\count($catsArr) > 1) {
                    $sql .= sprintf(' AND r.categories_id IN (%s)', implode(',', $catsArr));
                } else {
                    $sql .= sprintf(' AND r.categories_id = %d', $catsArr[0]);
                }
            }
            $sql .= ') ';
        }
        $sql .= ') ';

        return $sql;
    }

    /**
     * Function for searching on the site (by subject, searchname or advanced).
     *
     *
     * @param  array       $searchArr
     * @param              $groupName
     * @param              $sizeFrom
     * @param              $sizeTo
     * @param              $daysNew
     * @param              $daysOld
     * @param int          $offset
     * @param int          $limit
     * @param string|array $orderBy
     * @param int          $maxAge
     * @param array        $excludedCats
     * @param string       $type
     * @param array        $cat
     * @param int          $minSize
     * @param array        $tags
     *
     * @return array|\Illuminate\Database\Eloquent\Collection|mixed
     */
    public function search($searchArr, $groupName, $sizeFrom, $sizeTo, $daysNew, $daysOld, $offset = 0, $limit = 1000, $orderBy = '', $maxAge = -1, array $excludedCats = [], $type = 'basic', array $cat = [-1], $minSize = 0, array $tags = [])
    {
        $sizeRange = [
            1 => 1,
            2 => 2.5,
            3 => 5,
            4 => 10,
            5 => 20,
            6 => 30,
            7 => 40,
            8 => 80,
            9 => 160,
            10 => 320,
            11 => 640,
        ];
        if ($orderBy === '') {
            $orderBy = [];
            $orderBy[0] = 'postdate ';
            $orderBy[1] = 'desc ';
        } else {
            $orderBy = $this->getBrowseOrder($orderBy);
        }

        $searchFields = Arr::where($searchArr, function ($value) {
            return $value !== -1;
        });

        $results = $this->sphinxSearch->searchIndexes('releases_rt', '', [], $searchFields);

        $searchResult = Arr::pluck($results, 'id');

        $catQuery = '';
        if ($type === 'basic') {
            $catQuery = Category::getCategorySearch($cat);
        } elseif ($type === 'advanced' && (int) $cat[0] !== -1) {
            $catQuery = sprintf('AND r.categories_id = %d', $cat[0]);
        }
        $whereSql = sprintf(
            'WHERE r.passwordstatus %s AND r.nzbstatus = %d %s %s %s %s %s %s %s %s %s %s %s',
            $this->showPasswords(),
            NZB::NZB_ADDED,
            ! empty($tags) ? " AND tt.tag_name IN ('".implode("','", $tags)."')" : '',
            ($maxAge > 0 ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $maxAge) : ''),
            ((int) $groupName !== -1 ? sprintf(' AND r.groups_id = %d ', UsenetGroup::getIDByName($groupName)) : ''),
            (array_key_exists($sizeFrom, $sizeRange) ? ' AND r.size > '.(104857600 * (int) $sizeRange[$sizeFrom]).' ' : ''),
            (array_key_exists($sizeTo, $sizeRange) ? ' AND r.size < '.(104857600 * (int) $sizeRange[$sizeTo]).' ' : ''),
            $catQuery,
            ((int) $daysNew !== -1 ? sprintf(' AND r.postdate < (NOW() - INTERVAL %d DAY) ', $daysNew) : ''),
            ((int) $daysOld !== -1 ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $daysOld) : ''),
            (\count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
            (! empty($searchResult) ? 'AND r.id IN ('.implode(',', $searchResult).')' : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );
        $baseSql = sprintf(
            "SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id, cp.title AS parent_category, c.title AS sub_category,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				df.failed AS failed,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid,
				cp.id AS categoryparentid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.firstaired
			FROM releases r
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT JOIN usenet_groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			%s %s",
            $this->getConcatenatedCategoryIDs(),
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            $whereSql
        );
        $sql = sprintf(
            'SELECT * FROM (
				%s
			) r
			ORDER BY r.%s %s
			LIMIT %d OFFSET %d',
            $baseSql,
            $orderBy[0],
            $orderBy[1],
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }
        $releases = ! empty($searchResult) ? Release::fromQuery($sql) : collect();
        if ($releases->isNotEmpty()) {
            $releases[0]->_totalrows = $this->getPagerCount($baseSql);
        }
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Search function for API.
     *
     *
     * @param       $searchName
     * @param       $groupName
     * @param int   $offset
     * @param int   $limit
     * @param int   $maxAge
     * @param array $excludedCats
     * @param array $cat
     * @param int   $minSize
     * @param array $tags
     *
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function apiSearch($searchName, $groupName, $offset = 0, $limit = 1000, $maxAge = -1, array $excludedCats = [], array $cat = [-1], $minSize = 0, array $tags = [])
    {
        if ($searchName !== -1) {
            $searchResult = Arr::pluck($this->sphinxSearch->searchIndexes('releases_rt', $searchName, ['searchname']), 'id');
        }

        $catQuery = Category::getCategorySearch($cat);

        $whereSql = sprintf(
            'WHERE r.passwordstatus %s AND r.nzbstatus = %d %s %s %s %s %s %s %s',
            $this->showPasswords(),
            NZB::NZB_ADDED,
            ! empty($tags) ? " AND tt.tag_name IN ('".implode("','", $tags)."')" : '',
            ($maxAge > 0 ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $maxAge) : ''),
            ((int) $groupName !== -1 ? sprintf(' AND r.groups_id = %d ', UsenetGroup::getIDByName($groupName)) : ''),
            $catQuery,
            (\count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
            (! empty($searchResult) ? 'AND r.id IN ('.implode(',', $searchResult).')' : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );
        $baseSql = sprintf(
            "SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id, m.imdbid, m.tmdbid, m.traktid, cp.title AS parent_category, c.title AS sub_category,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				cp.id AS categoryparentid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.firstaired, tve.title, tve.series, tve.episode
			FROM releases r
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT JOIN movieinfo m ON m.id = r.movieinfo_id
			LEFT JOIN usenet_groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			%s %s",
            $this->getConcatenatedCategoryIDs(),
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            $whereSql
        );
        $sql = sprintf(
            'SELECT * FROM (
				%s
			) r
			ORDER BY r.postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }
        if ($searchName !== -1 && ! empty($searchResult)) {
            $releases = Release::fromQuery($sql);
        } elseif ($searchName !== -1 && empty($searchResult)) {
            $releases = collect();
        } else {
            $releases = Release::fromQuery($sql);
        }
        if ($releases->isNotEmpty()) {
            $releases[0]->_totalrows = $this->getPagerCount($baseSql);
        }
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Search TV Shows via API.
     *
     *
     * @param array $siteIdArr
     * @param string $series
     * @param string $episode
     * @param string $airdate
     * @param int $offset
     * @param int $limit
     * @param string $name
     * @param array $cat
     * @param int $maxAge
     * @param int $minSize
     * @param array $excludedCategories
     * @param array $tags
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function tvSearch(array $siteIdArr = [], $series = '', $episode = '', $airdate = '', $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1, $minSize = 0, array $excludedCategories = [], array $tags = [])
    {
        $siteSQL = [];
        $showSql = '';

        foreach ($siteIdArr as $column => $Id) {
            if ($Id > 0) {
                $siteSQL[] = sprintf('v.%s = %d', $column, $Id);
            }
        }

        if (\count($siteSQL) > 0) {
            // If we have show info, find the Episode ID/Video ID first to avoid table scans
            $showQry = sprintf(
                "
				SELECT
					v.id AS video,
					GROUP_CONCAT(tve.id SEPARATOR ',') AS episodes
				FROM videos v
				LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
				WHERE (%s) %s %s %s
				GROUP BY v.id
				LIMIT 1",
                implode(' OR ', $siteSQL),
                ($series !== '' ? sprintf('AND tve.series = %d', (int) preg_replace('/^s0*/i', '', $series)) : ''),
                ($episode !== '' ? sprintf('AND tve.episode = %d', (int) preg_replace('/^e0*/i', '', $episode)) : ''),
                ($airdate !== '' ? sprintf('AND DATE(tve.firstaired) = %s', escapeString($airdate)) : '')
            );
            $show = Release::fromQuery($showQry);

            if (! empty($show[0])) {
                if ((! empty($series) || ! empty($episode) || ! empty($airdate)) && $show[0]->episodes !== '') {
                    $showSql = sprintf('AND r.tv_episodes_id IN (%s)', $show[0]->episodes);
                } elseif ((int) $show[0]->video > 0) {
                    $showSql = 'AND r.videos_id = '.$show[0]->video;
                    // If $series is set but episode is not, return Season Packs only
                    if (! empty($series) && empty($episode)) {
                        $showSql .= ' AND r.tv_episodes_id = 0';
                    }
                } else {
                    // If we were passed Episode Info and no match was found, do not run the query
                    return [];
                }
            } else {
                // If we were passed Site ID Info and no match was found, do not run the query
                return [];
            }
        }
        // If $name is set it is a fallback search, add available SxxExx/airdate info to the query
        if (! empty($name) && $showSql === '') {
            if (! empty($series) && (int) $series < 1900) {
                $name .= sprintf(' S%s', str_pad($series, 2, '0', STR_PAD_LEFT));
                if (! empty($episode) && strpos($episode, '/') === false) {
                    $name .= sprintf('E%s', str_pad($episode, 2, '0', STR_PAD_LEFT));
                }
            } elseif (! empty($airdate)) {
                $name .= sprintf(' %s', str_replace(['/', '-', '.', '_'], ' ', $airdate));
            }
        }

        if (! empty($name)) {
            $searchResult = Arr::pluck($this->sphinxSearch->searchIndexes('releases_rt', $name, ['searchname']), 'id');
        }

        $whereSql = sprintf(
            'WHERE r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s %s %s',
            NZB::NZB_ADDED,
            $this->showPasswords(),
            ! empty($tags) ? " AND tt.tag_name IN ('".implode("','", $tags)."')" : '',
            $showSql,
            ((! empty($name) && ! empty($searchResult)) ? 'AND r.id IN ('.implode(',', $searchResult).')' : ''),
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? sprintf('AND r.postdate > NOW() - INTERVAL %d DAY', $maxAge) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : ''),
            ! empty($excludedCategories) ? sprintf('AND r.categories_id NOT IN('.implode(',', $excludedCategories).')') : ''
        );
        $baseSql = sprintf(
            "SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id,
				v.title, v.countries_id, v.started, v.tvdb, v.trakt,
					v.imdb, v.tmdb, v.tvmaze, v.tvrage, v.source,
				tvi.summary, tvi.publisher, tvi.image,
				tve.series, tve.episode, tve.se_complete, tve.title, tve.firstaired, tve.summary, cp.title AS parent_category, c.title AS sub_category,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid
			FROM releases r
			LEFT OUTER JOIN videos v ON r.videos_id = v.id AND v.type = 0
			LEFT OUTER JOIN tv_info tvi ON v.id = tvi.videos_id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN usenet_groups g ON g.id = r.groups_id
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			%s %s",
            $this->getConcatenatedCategoryIDs(),
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            $whereSql
        );
        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }
        ((! empty($name) && ! empty($searchResult)) || empty($name)) ? $releases = Release::fromQuery($sql) : [];
        if (! empty($releases) && $releases->isNotEmpty()) {
            $releases[0]->_totalrows = $this->getPagerCount(
                preg_replace('#LEFT(\s+OUTER)?\s+JOIN\s+(?!tv_episodes)\s+.*ON.*=.*\n#i', ' ', $baseSql)
            );
        }

        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Search TV Shows via APIv2.
     *
     *
     * @param array $siteIdArr
     * @param string $series
     * @param string $episode
     * @param string $airdate
     * @param int $offset
     * @param int $limit
     * @param string $name
     * @param array $cat
     * @param int $maxAge
     * @param int $minSize
     * @param array $excludedCategories
     * @param array $tags
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function apiTvSearch(array $siteIdArr = [], $series = '', $episode = '', $airdate = '', $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1, $minSize = 0, array $excludedCategories = [], array $tags = [])
    {
        $siteSQL = [];
        $showSql = '';
        foreach ($siteIdArr as $column => $Id) {
            if ($Id > 0) {
                $siteSQL[] = sprintf('v.%s = %d', $column, $Id);
            }
        }

        if (\count($siteSQL) > 0) {
            // If we have show info, find the Episode ID/Video ID first to avoid table scans
            $showQry = sprintf(
                "
				SELECT
					v.id AS video,
					GROUP_CONCAT(tve.id SEPARATOR ',') AS episodes
				FROM videos v
				LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
				WHERE (%s) %s %s %s
				GROUP BY v.id
				LIMIT 1",
                implode(' OR ', $siteSQL),
                ($series !== '' ? sprintf('AND tve.series = %d', (int) preg_replace('/^s0*/i', '', $series)) : ''),
                ($episode !== '' ? sprintf('AND tve.episode = %d', (int) preg_replace('/^e0*/i', '', $episode)) : ''),
                ($airdate !== '' ? sprintf('AND DATE(tve.firstaired) = %s', escapeString($airdate)) : '')
            );
            $show = Release::fromQuery($showQry);

            if ($show->isNotEmpty()) {
                if ((! empty($series) || ! empty($episode) || ! empty($airdate)) && $show[0]->episodes != '') {
                    $showSql = sprintf('AND r.tv_episodes_id IN (%s)', $show[0]->episodes);
                } elseif ((int) $show[0]->video > 0) {
                    $showSql = 'AND r.videos_id = '.$show[0]->video;
                    // If $series is set but episode is not, return Season Packs only
                    if (! empty($series) && empty($episode)) {
                        $showSql .= ' AND r.tv_episodes_id = 0';
                    }
                } else {
                    // If we were passed Episode Info and no match was found, do not run the query
                    return [];
                }
            } else {
                // If we were passed Site ID Info and no match was found, do not run the query
                return [];
            }
        }
        // If $name is set it is a fallback search, add available SxxExx/airdate info to the query
        if (! empty($name) && $showSql === '') {
            if (! empty($series) && (int) $series < 1900) {
                $name .= sprintf(' S%s', str_pad($series, 2, '0', STR_PAD_LEFT));
                if (! empty($episode) && strpos($episode, '/') === false) {
                    $name .= sprintf('E%s', str_pad($episode, 2, '0', STR_PAD_LEFT));
                }
            } elseif (! empty($airdate)) {
                $name .= sprintf(' %s', str_replace(['/', '-', '.', '_'], ' ', $airdate));
            }
        }

        if (! empty($name)) {
            $searchResult = Arr::pluck($this->sphinxSearch->searchIndexes('releases_rt', $name, ['searchname']), 'id');
        }

        $whereSql = sprintf(
            'WHERE r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s %s %s',
            NZB::NZB_ADDED,
            $this->showPasswords(),
            ! empty($tags) ? " AND tt.tag_name IN ('".implode("','", $tags)."')" : '',
            $showSql,
            (! empty($searchResult) ? 'AND r.id IN ('.implode(',', $searchResult).')' : ''),
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? sprintf('AND r.postdate > NOW() - INTERVAL %d DAY', $maxAge) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : ''),
            ! empty($excludedCategories) ? sprintf('AND r.categories_id NOT IN('.implode(',', $excludedCategories).')') : ''
        );
        $baseSql = sprintf(
            "SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.tv_episodes_id,
				v.title, v.type, v.tvdb, v.trakt,v.imdb, v.tmdb, v.tvmaze, v.tvrage,
				tve.series, tve.episode, tve.se_complete, tve.title, tve.firstaired, cp.title AS parent_category, c.title AS sub_category,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name
			FROM releases r
			LEFT OUTER JOIN videos v ON r.videos_id = v.id AND v.type = 0
			LEFT OUTER JOIN tv_info tvi ON v.id = tvi.videos_id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN usenet_groups g ON g.id = r.groups_id
			%s %s",
            $this->getConcatenatedCategoryIDs(),
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            $whereSql
        );
        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }
        $releases = Release::fromQuery($sql);
        if ($releases->isNotEmpty()) {
            $releases[0]->_totalrows = $this->getPagerCount(
                preg_replace('#LEFT(\s+OUTER)?\s+JOIN\s+(?!tv_episodes)\s+.*ON.*=.*\n#i', ' ', $baseSql)
            );
        }

        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Search anime releases.
     *
     *
     * @param $aniDbID
     * @param int $offset
     * @param int $limit
     * @param string $name
     * @param array $cat
     * @param int $maxAge
     * @param array $excludedCategories
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function animeSearch($aniDbID, $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1, array $excludedCategories = [])
    {
        if (! empty($name)) {
            $searchResult = Arr::pluck($this->sphinxSearch->searchIndexes('releases_rt', $name, ['searchname']), 'id');
        }

        $whereSql = sprintf(
            'WHERE r.passwordstatus %s
			AND r.nzbstatus = %d
			%s %s %s %s %s',
            $this->showPasswords(),
            NZB::NZB_ADDED,
            ($aniDbID > -1 ? sprintf(' AND r.anidbid = %d ', $aniDbID) : ''),
            (! empty($searchResult) ? 'AND r.id IN ('.implode(',', $searchResult).')' : ''),
            ! empty($excludedCategories) ? sprintf('AND r.categories_id NOT IN('.implode(',', $excludedCategories).')') : '',
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
        );
        $baseSql = sprintf(
            "SELECT r.*, cp.title AS parent_category, c.title AS sub_category,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid
			FROM releases r
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN usenet_groups g ON g.id = r.groups_id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN releaseextrafull re ON re.releases_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );
        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }
        $releases = Release::fromQuery($sql);
        if ($releases->isNotEmpty()) {
            $releases[0]->_totalrows = $this->getPagerCount($baseSql);
        }
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Movies search through API and site.
     *
     *
     * @param int $imDbId
     * @param int $tmDbId
     * @param int $traktId
     * @param int $offset
     * @param int $limit
     * @param string $name
     * @param array $cat
     * @param int $maxAge
     * @param int $minSize
     * @param array $excludedCategories
     * @param array $tags
     * @return \Illuminate\Database\Eloquent\Collection|mixed
     */
    public function moviesSearch($imDbId = -1, $tmDbId = -1, $traktId = -1, $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1, $minSize = 0, array $excludedCategories = [], array $tags = [])
    {
        if (! empty($name)) {
            $searchResult = Arr::pluck($this->sphinxSearch->searchIndexes('releases_rt', $name, ['searchname']), 'id');
        }

        $whereSql = sprintf(
            'WHERE r.categories_id BETWEEN '.Category::MOVIE_ROOT.' AND '.Category::MOVIE_OTHER.'
			AND r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s %s %s %s',
            NZB::NZB_ADDED,
            $this->showPasswords(),
            (! empty($searchResult) ? 'AND r.id IN ('.implode(',', $searchResult).')' : ''),
            ! empty($tags) ? " AND tt.tag_name IN ('".implode("','", $tags)."')" : '',
            ($imDbId !== -1 && is_numeric($imDbId)) ? sprintf(' AND m.imdbid = %d ', str_pad($imDbId, 7, '0', STR_PAD_LEFT)) : '',
            ($tmDbId !== -1 && is_numeric($tmDbId)) ? sprintf(' AND m.tmdbid = %d ', $tmDbId) : '',
            ($traktId !== -1 && is_numeric($traktId)) ? sprintf(' AND m.traktid = %d ', $traktId) : '',
            ! empty($excludedCategories) ? sprintf('AND r.categories_id NOT IN('.implode(',', $excludedCategories).')') : '',
            Category::getCategorySearch($cat),
            $maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '',
            $minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : ''
        );
        $baseSql = sprintf(
            "SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments, r.adddate, r.imdbid, r.videos_id, r.tv_episodes_id, m.imdbid, m.tmdbid, m.traktid, cp.title AS parent_category, c.title AS sub_category,
				concat(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid
			FROM releases r
			LEFT JOIN movieinfo m ON m.id = r.movieinfo_id
			LEFT JOIN usenet_groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			%s %s",
            $this->getConcatenatedCategoryIDs(),
            ! empty($tags) ? ' LEFT JOIN tagging_tagged tt ON tt.taggable_id = r.id' : '',
            $whereSql
        );
        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }
        $releases = Release::fromQuery($sql);
        if ($releases->isNotEmpty()) {
            $releases[0]->_totalrows = $this->getPagerCount($baseSql);
        }
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * @param $currentID
     * @param $name
     * @param array $excludedCats
     * @return array|\Illuminate\Database\Eloquent\Collection
     */
    public function searchSimilar($currentID, $name, array $excludedCats = [])
    {
        // Get the category for the parent of this release.
        $ret = false;
        $currRow = Release::getCatByRelId($currentID);
        if ($currRow !== null) {
            $catRow = Category::find($currRow['categories_id']);
            $parentCat = $catRow['parentid'];

            $results = $this->search(['searchname' => getSimilarName($name)], -1, '', '', -1, -1, 0, config('nntmux.items_per_page'), '', -1, $excludedCats, [$parentCat]);
            if (! $results) {
                return $results;
            }

            $ret = [];
            foreach ($results as $res) {
                if ($res['id'] !== $currentID && $res['categoryparentid'] === $parentCat) {
                    $ret[] = $res;
                }
            }
        }

        return $ret;
    }

    /**
     * @param array $guids
     *
     * @return string
     * @throws \Exception
     */
    public function getZipped(array $guids = []): string
    {
        $nzb = new NZB();
        $zipped = new Zipper();
        $zippedFileName = now()->format('Ymdhis').'.nzb.zip';
        $zippedFilePath = resource_path().'/tmp/'.$zippedFileName;

        foreach ($guids as $guid) {
            $nzbPath = $nzb->NZBPath($guid);

            if ($nzbPath) {
                $nzbContents = Utility::unzipGzipFile($nzbPath);

                if ($nzbContents) {
                    $filename = $guid;
                    $r = Release::getByGuid($guid);
                    if ($r) {
                        $filename = $r['searchname'];
                    }
                    $zipped->make($zippedFilePath)->addString($filename.'.nzb', $nzbContents);
                }
            }
        }

        $zipped->close();

        return File::isFile($zippedFilePath) ? $zippedFilePath : '';
    }

    /**
     * Get count of releases for pager.
     *
     *
     * @param string $query The query to get the count from.
     *
     * @return int
     */
    private function getPagerCount($query): int
    {
        $sql = sprintf(
            'SELECT COUNT(z.id) AS count FROM (%s LIMIT %s) z',
            preg_replace('/SELECT.+?FROM\s+releases/is', 'SELECT r.id FROM releases', $query),
            config('nntmux.max_pager_results')
        );
        $count = Cache::get(md5($sql));
        if ($count !== null) {
            return $count;
        }
        $count = Release::fromQuery($sql);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_short'));
        Cache::put(md5($sql), $count[0]->count, $expiresAt);

        return $count[0]->count ?? 0;
    }
}
