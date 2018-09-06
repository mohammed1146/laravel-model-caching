<?php 
namespace GeneaLabs\LaravelModelCaching\Traits;

use Fico7489\Laravel\Pivot\Traits\PivotEventTrait;

trait Cachable
{
    use PivotEventTrait, Caching, ModelCaching;
}
