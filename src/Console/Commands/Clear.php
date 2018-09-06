<?php namespace GeneaLabs\LaravelModelCaching\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class Clear extends Command
{
    protected 
        $signature = 'modelCache:clear {--model=}',
        $description = 'Flush cache for a given model. If no model is given, entire model-cache is flushed.';

    /**
     * handle model cache
     *
     * @return void
     */
    public function handle()
    {
        $option = $this->option('model');

        if (! $option) {
            return $this->flushEntireCache();
        }

        return $this->flushModelCache($option);
    }

    /**
     * flush entire cache
     *
     * @return integer
     */
    protected function flushEntireCache() : int
    {
        cache()
            ->store(config('laravel-model-caching.store'))
            ->flush();

        $this->info("✔︎ Entire model cache has been flushed.");

        return 0;
    }

    /**
     * flush model cache
     *
     * @param string $option
     * @return integer
     */
    protected function flushModelCache(string $option) : int
    {
        $model = new $option;
        $usesCachableTrait = $this->getAllTraitsUsedByClass($option)
            ->contains("GeneaLabs\LaravelModelCaching\Traits\Cachable");

        if (! $usesCachableTrait) {
            $this->error("'{$option}' is not an instance of CachedModel.");
            $this->line("Only CachedModel instances can be flushed.");

            return 1;
        }

        $model->flushCache();
        $this->info("✔︎ Cache for model '{$option}' has been flushed.");

        return 0;
    }

   /**
    * get all traits usesd by the given class name
    *
    * @param string $classname
    * @param boolean $autoload
    * @return Collection
    */
    protected function getAllTraitsUsedByClass(
        string $classname,
        bool $autoload = true
    ) : Collection {
        $traits = collect();

        if (class_exists($classname, $autoload)) {
            $traits = collect(class_uses($classname, $autoload));
        }

        $parentClass = get_parent_class($classname);

        if ($parentClass) {
            $traits = $traits
                ->merge($this->getAllTraitsUsedByClass($parentClass, $autoload));
        }

        return $traits;
    }
}
