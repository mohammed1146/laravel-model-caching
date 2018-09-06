<?php 
namespace GeneaLabs\LaravelModelCaching\Traits;

trait CachePrefixing
{
    /**
     * get cache prefix
     *
     * @return string
     */
    protected function getCachePrefix() : string
    {
        return "genealabs:laravel-model-caching:"
            . $this->getDatabaseConnectionName() . ":"
            . $this->getDatabaseName() . ":"
            . (config("laravel-model-caching.cache-prefix")
                ? config("laravel-model-caching.cache-prefix", "") . ":"
                : "");
    }

    /**
     * get database connection name
     *
     * @return string
     */
    protected function getDatabaseConnectionName() : string
    {
        return $this->query->connection->getName();
    }

    /**
     * get database name
     *
     * @return string
     */
    protected function getDatabaseName() : string
    {
        return $this->query->connection->getDatabaseName();
    }
}
