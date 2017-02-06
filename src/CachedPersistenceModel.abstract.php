<?php
namespace CsrDelft\Orm;

use CsrDelft\Orm\Entity\PersistentEntity;
use PDOStatement;

/**
 * CachedPersistenceModel.abstract.php
 *
 * @author P.W.G. Brussee <brussee@live.nl>
 *
 * Uses runtime cache and Memcache to provide caching on top of PersistenceModel.
 * Lazy loading: request-multiple-retrieve-once entities by primary key from foreign key relations.
 * Prefetch: grouping queries of foreign key relations beforehand.
 *
 * N.B. modifying objects in the cache affects every reference to it!
 *
 * Note: cache on create is not possible due to cache key being based on PK
 *       and PK may be set after create by child class.
 *
 */
abstract class CachedPersistenceModel extends PersistenceModel {

	private $runtime_cache = array();
	/**
	 * Store prefetch resultset as a whole in memcache
	 * @var boolean
	 */
	protected $memcache_prefetch = false;

	/**
	 * Calculate key for caching.
	 *
	 * @param array $primary_key_values
	 * @return string
	 */
	private function cacheKey(array $primary_key_values) {
		return static::ORM . crc32(implode('-', $primary_key_values));
	}

	protected function isCached($key, $memcache = false) {
		if (isset($this->runtime_cache[$key])) {
			return true;
		} elseif ($memcache AND CsrMemcache::isAvailable()) {
			// exists without retrieval
			if (CsrMemcache::instance()->add($key, '')) {
				CsrMemcache::instance()->delete($key);
				return false;
			}
			return true;
		}
		return false;
	}

	protected function getCached($key, $memcache = false) {
		if (array_key_exists($key, $this->runtime_cache)) {
			return $this->runtime_cache[$key];
		} elseif ($memcache AND CsrMemcache::isAvailable()) {
			$cache = CsrMemcache::instance()->get($key);
			if ($cache !== false) {
				$value = unserialize($cache);
				// unserialize once 
				$this->setCache($key, $value, false);
				return $value;
			}
		}
	}

	protected function setCache($key, $value, $memcache = false) {
		$this->runtime_cache[$key] = $value;
		if ($memcache AND CsrMemcache::isAvailable()) {
			CsrMemcache::instance()->set($key, serialize($value));
		}
	}

	protected function unsetCache($key, $memcache = false) {
		unset($this->runtime_cache[$key]);
		if ($memcache AND CsrMemcache::isAvailable()) {
			CsrMemcache::instance()->delete($key);
		}
	}

	/**
	 * Remove from memcache rather than flushing.
	 *
	 * @param boolean $memcache This can be used to partially clear memcache.
	 */
	protected function flushCache($memcache = false) {
		if ($memcache AND CsrMemcache::isAvailable()) {
			CsrMemcache::instance()->flush();
		}
		$this->runtime_cache = array();
	}

	/**
	 * Cache entity without persistent storage.
	 * Optional: put in memcache.
	 *
	 * @param PersistentEntity $entity
	 * @param boolean $memcache
	 * @param boolean $overwrite
	 */
	protected function cache(PersistentEntity $entity, $memcache = false, $overwrite = false) {
		$key = $this->cacheKey($entity->getValues(true));
		if (!$overwrite AND $this->isCached($key, $memcache)) {
			$entity = $this->getCached($key, $memcache);
		} else {
			$this->setCache($key, $entity, $memcache);
		}
		return $entity;
	}

	/**
	 * Cache entire resultset from a PDOStatement.
	 * Optional: put in memcache.
	 *
	 * @param PDOStatement|array $resultset
	 * @param boolean $memcache
	 * @return array resultset of PDOStatement
	 */
	protected function cacheResult($resultset, $memcache = false) {
		$cached = array();
		foreach ($resultset as $entity) {
			$cached[] = $this->cache($entity, $memcache);
		}
		return $cached;
	}

	/**
	 * Find and cache existing entities with optional search criteria.
	 * Retrieves all attributes.
	 * Optional: store resultset as a whole in memcache.
	 *
	 * @param string $criteria WHERE
	 * @param array $criteria_params optional named parameters
	 * @param string $groupby GROUP BY
	 * @param string $orderby ORDER BY
	 * @param int $limit max amount of results
	 * @param int $start results from index
	 * @return array
	 */
	public function prefetch($criteria = null, array $criteria_params = array(), $groupby = null, $orderby = null, $limit = null, $start = 0) {
		$key = $this->prefetchKey($criteria, $criteria_params, $groupby, $orderby, $limit, $start);
		if ($this->isCached($key, $this->memcache_prefetch)) {
			$result = $this->getCached($key, $this->memcache_prefetch);
		} else {
			$result = $this->find($criteria, $criteria_params, $groupby, $orderby, $limit, $start);
		}
		$cached = $this->cacheResult($result, false);
		if ($result instanceof PDOStatement) {
			$this->setCache($key, $cached, $this->memcache_prefetch);
		}
		return $cached;
	}

	/**
	 * Calculate key for caching prefetch resultset.
	 *
	 * @param array $params
	 * @return string
	 */
	private function prefetchKey($criteria, array $criteria_params, $groupby, $orderby, $limit, $start) {
		$params = array($criteria, implode('+', $criteria_params), $groupby, $orderby, $limit, $start);
		return get_class($this) . crc32(implode('-', $params));
	}

	/**
	 * Check if enitity with primary key exists.
	 *
	 * @param array $primary_key_values
	 * @return boolean primary key exists
	 */
	protected function existsByPrimaryKey(array $primary_key_values) {
		$key = $this->cacheKey($primary_key_values);
		if ($this->isCached($key)) {
			return $this->getCached($key) !== false;
		} else {
			return parent::existsByPrimaryKey($primary_key_values);
		}
	}

	/**
	 * Save new entity.
	 *
	 * @param PersistentEntity $entity
	 * @return string last insert id
	 */
	public function create(PersistentEntity $entity) {
		if ($this->memcache_prefetch) {
			$this->flushCache(true);
		}
		return parent::create($entity);
	}

	/**
	 * Load and cache saved entity data and create new object.
	 *
	 * @param array $primary_key_values
	 * @return PersistentEntity|false
	 */
	protected function retrieveByPrimaryKey(array $primary_key_values) {
		$key = $this->cacheKey($primary_key_values);
		if ($this->isCached($key)) {
			return $this->getCached($key);
		}
		$result = parent::retrieveByPrimaryKey($primary_key_values);
		$this->setCache($key, $result);
		return $result;
	}

	/**
	 * Requires positional values.
	 *
	 * @param array $primary_key_values
	 * @return int number of rows affected
	 */
	protected function deleteByPrimaryKey(array $primary_key_values) {
		if ($this->memcache_prefetch) {
			$this->flushCache(true);
		} else {
			$this->unsetCache($this->cacheKey($primary_key_values), true);
		}
		return parent::deleteByPrimaryKey($primary_key_values);
	}

	/**
	 * Save existing entity.
	 * Sparse attributes that have not been retrieved are excluded by PersistentEntity->getValues().
	 *
	 * @param PersistentEntity $entity
	 * @return int number of rows affected
	 */
	public function update(PersistentEntity $entity) {
		if ($this->memcache_prefetch) {
			$this->flushCache(true);
		}
		$result = parent::update($entity);
		$this->cache($entity, false, true);
		return $result;
	}

}
