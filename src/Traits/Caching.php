<?php 
namespace GeneaLabs\LaravelModelCaching\Traits;

use Carbon\Carbon;
use GeneaLabs\LaravelModelCaching\CacheKey;
use GeneaLabs\LaravelModelCaching\CacheTags;
use Illuminate\Cache\TaggableStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

trait Caching
{
    protected $isCachable = true;

    /**
     * do caching for the given array
     *
     * @param array $tags
     * @return void
     */
    public function cache(array $tags = [])
    {
        $cache = cache();

        if (config('laravel-model-caching.store')) {
            $cache = $cache->store(config('laravel-model-caching.store'));
        }

        if (is_subclass_of($cache->getStore(), TaggableStore::class)) {
            $cache = $cache->tags($tags);
        }

        return $cache;
    }

    /**
     * disable model caching
     *
     * @return void
     */
    public function disableModelCaching()
    {
        $this->isCachable = false;

        return $this;
    }

    /**
     * flush Cache
     *
     * @param array $tags
     * @return void
     */
    public function flushCache(array $tags = [])
    {
        if (count($tags) === 0) {
            $tags = $this->makeCacheTags();
        }

        $this->cache($tags)->flush();

        [$cacheCooldown] = $this->getModelCacheCooldown($this);

        if ($cacheCooldown) {
            $cachePrefix = $this->getCachePrefix();
            $modelClassName = get_class($this);
            $cacheKey = "{$cachePrefix}:{$modelClassName}-cooldown:saved-at";

            $this->cache()
                ->rememberForever($cacheKey, function () {
                    return (new Carbon)->now();
                });
        }
    }

    /**
     * get cache prefix
     *
     * @return string
     */
    protected function getCachePrefix() : string
    {
        return "genealabs:laravel-model-caching:"
            . (config('laravel-model-caching.cache-prefix')
                ? config('laravel-model-caching.cache-prefix', '') . ":"
                : "");
    }

    /**
     * make cache key
     *
     * @param array $columns
     * @param [type] $idColumn
     * @param string $keyDifferentiator
     * @return string
     */
    protected function makeCacheKey(
        array $columns = ['*'],
        $idColumn = null,
        string $keyDifferentiator = ''
    ) : string 
    {
        $eagerLoad = $this->eagerLoad ?? [];
        $model = $this->model ?? $this;
        $query = $this->query ?? app(Builder::class);

        return (new CacheKey($eagerLoad, $model, $query))
            ->make($columns, $idColumn, $keyDifferentiator);
    }

    /**
     * make cache tags
     *
     * @return array
     */
    protected function makeCacheTags() : array
    {
        $eagerLoad = $this->eagerLoad ?? [];
        $model = $this->model ?? $this;
        $query = $this->query ?? app(Builder::class);

        $tags = (new CacheTags($eagerLoad, $model, $query))
            ->make();

        return $tags;
    }

    /**
     * get model cache cool down
     *
     * @param Model $instance
     * @return void
     */
    public function getModelCacheCooldown(Model $instance)
    {
        $cachePrefix = $this->getCachePrefix();
        $modelClassName = get_class($instance);
        [$cacheCooldown, $invalidatedAt, $savedAt] = $this
            ->getCacheCooldownDetails($instance, $cachePrefix, $modelClassName);

        if (! $cacheCooldown || $cacheCooldown === 0) {
            return [null, null, null];
        }

        return [
            $cacheCooldown,
            $invalidatedAt,
            $savedAt,
        ];
    }

    /**
     * get cache cool down details
     *
     * @param Model $instance
     * @param string $cachePrefix
     * @param string $modelClassName
     * @return array
     */
    protected function getCacheCooldownDetails(
        Model $instance,
        string $cachePrefix,
        string $modelClassName
    ) : array {
        return [
            $instance
                ->cache()
                ->get("{$cachePrefix}:{$modelClassName}-cooldown:seconds"),
            $instance
                ->cache()
                ->get("{$cachePrefix}:{$modelClassName}-cooldown:invalidated-at"),
            $instance
                ->cache()
                ->get("{$cachePrefix}:{$modelClassName}-cooldown:saved-at"),
        ];
    }

    /**
     * check cool down and remove if expired
     *
     * @param Model $instance
     * @return void
     */
    protected function checkCooldownAndRemoveIfExpired(Model $instance)
    {
        [$cacheCooldown, $invalidatedAt] = $this->getModelCacheCooldown($instance);

        if (! $cacheCooldown
            || (new Carbon)->now()->diffInSeconds($invalidatedAt) < $cacheCooldown
        ) {
            return;
        }

        $cachePrefix = $this->getCachePrefix();
        $modelClassName = get_class($instance);

        $instance
            ->cache()
            ->forget("{$cachePrefix}:{$modelClassName}-cooldown:invalidated-at");
        $instance
            ->cache()
            ->forget("{$cachePrefix}:{$modelClassName}-cooldown:invalidated-at");
        $instance
            ->cache()
            ->forget("{$cachePrefix}:{$modelClassName}-cooldown:saved-at");
        $instance->flushCache();
    }

    /**
     * check cooldown and flush after persiting
     *
     * @param Model $instance
     * @return void
     */
    protected function checkCooldownAndFlushAfterPersiting(Model $instance)
    {
        [$cacheCooldown, $invalidatedAt] = $instance->getModelCacheCooldown($instance);

        if (! $cacheCooldown) {
            $instance->flushCache();

            return;
        }

        $this->setCacheCooldownSavedAtTimestamp($instance);

        if ((new Carbon)->now()->diffInSeconds($invalidatedAt) >= $cacheCooldown) {
            $instance->flushCache();
        }
    }

    /**
     * check if is cachable
     *
     * @return boolean
     */
    public function isCachable() : bool
    {
        return $this->isCachable
            && ! config('laravel-model-caching.disabled');
    }

    /**
     * set cache cooldown and save timestamps
     *
     * @param Model $instance
     * @return void
     */
    protected function setCacheCooldownSavedAtTimestamp(Model $instance)
    {
        $cachePrefix = $this->getCachePrefix();
        $modelClassName = get_class($instance);
        $cacheKey = "{$cachePrefix}:{$modelClassName}-cooldown:saved-at";

        $instance->cache()
            ->rememberForever($cacheKey, function () {
                return (new Carbon)->now();
            });
    }
}
