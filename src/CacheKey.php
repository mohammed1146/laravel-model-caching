<?php 
namespace GeneaLabs\LaravelModelCaching;

use GeneaLabs\LaravelModelCaching\Traits\CachePrefixing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class CacheKey
{
    use CachePrefixing;

    protected 
        $eagerLoad,
        $model,
        $query,
        $currentBinding = 0;

    /**
     * cache key constructor
     *
     * @param array $eagerLoad
     * @param Model $model
     * @param Builder $query
     */
    public function __construct(array $eagerLoad, Model $model, Builder $query) 
    {
        $this->eagerLoad = $eagerLoad;

        $this->model = $model;

        $this->query = $query;
    }

    /**
     * make cache
     *
     * @param array $columns
     * @param [type] $idColumn
     * @param string $keyDifferentiator
     * @return string
     */
    public function make(array $columns = ["*"], $idColumn = null, string $keyDifferentiator = "") : string 
    {
        $key = $this->getCachePrefix();

        $key .= $this->getModelSlug();

        $key .= $this->getIdColumn($idColumn ?: "");

        $key .= $this->getQueryColumns($columns);

        $key .= $this->getWhereClauses();

        $key .= $this->getWithModels();

        $key .= $this->getOrderByClauses();

        $key .= $this->getOffsetClause();

        $key .= $this->getLimitClause();

        $key .= $keyDifferentiator;

        return $key;
    }

    /**
     * get id column
     *
     * @param string $idColumn
     * @return string
     */
    protected function getIdColumn(string $idColumn) : string
    {
        return $idColumn ? "_{$idColumn}" : "";
    }

    /**
     * get limit clause
     *
     * @return string
     */
    protected function getLimitClause() : string
    {
        if (! $this->query->limit) {
            return "";
        }

        return "-limit_{$this->query->limit}";
    }

    /**
     * get model slug
     *
     * @return string
     */
    protected function getModelSlug() : string
    {
        return str_slug(get_class($this->model));
    }

    /**
     * get offset clause
     *
     * @return string
     */
    protected function getOffsetClause() : string
    {
        if (! $this->query->offset) {
            return "";
        }

        return "-offset_{$this->query->offset}";
    }

    /**
     * get order by clause
     *
     * @return string
     */
    protected function getOrderByClauses() : string
    {
        $orders = collect($this->query->orders);

        return $orders
            ->reduce(function ($carry, $order) {
                if (($order["type"] ?? "") === "Raw") {
                    return $carry . "_orderByRaw_" . str_slug($order["sql"]);
                }

                return $carry . "_orderBy_" . $order["column"] . "_" . $order["direction"];
            })
            ?: "";
    }

    /**
     * get query columns
     *
     * @param array $columns
     * @return string
     */
    protected function getQueryColumns(array $columns) : string
    {
        if ($columns === ["*"] || $columns === []) {
            return "";
        }

        return "_" . implode("_", $columns);
    }

    /**
     * get type clause
     *
     * @param [type] $where
     * @return string
     */
    protected function getTypeClause($where) : string
    {
        $type = in_array($where["type"], ["In", "NotIn", "Null", "NotNull", "between", "NotInSub", "InSub"])
            ? strtolower($where["type"])
            : strtolower($where["operator"]);

        return str_replace(" ", "_", $type);
    }

    /**
     * get values clause
     *
     * @param array $where
     * @return string
     */
    protected function getValuesClause(array $where = null) : string
    {
        if (in_array($where["type"], ["NotNull", "Null"])) {
            return "";
        }

        $values = $this->getValuesFromWhere($where);

        $values = $this->getValuesFromBindings($where, $values);

        return "_" . $values;
    }

    /**
     * get values from where
     *
     * @param array $where
     * @return string
     */
    protected function getValuesFromWhere(array $where) : string
    {
        if (array_get($where, "query")) {
            $prefix = $this->getCachePrefix();
            $subKey = (new self($this->eagerLoad, $this->model, $where["query"]))
                ->make();
            $subKey = str_replace($prefix, "", $subKey);
            $subKey = str_replace($this->getModelSlug(), "", $subKey);
            $classParts = explode("\\", get_class($this->model));
            $subKey = strtolower(array_pop($classParts)) . $subKey;

            return $subKey;
        }

        if (is_array(array_get($where, "values"))) {
            return implode("_", $where["values"]);
        }

        return array_get($where, "value", "");
    }

    /**
     * get values from binding
     *
     * @param array $where
     * @param string $values
     * @return string
     */
    protected function getValuesFromBindings(array $where, string $values) : string
    {
        if (! $values && ($this->query->bindings["where"][$this->currentBinding] ?? false)) {

            $values = $this->query->bindings["where"][$this->currentBinding];

            $this->currentBinding++;

            if ($where["type"] === "between") {
                $values .= "_" . $this->query->bindings["where"][$this->currentBinding];
                $this->currentBinding++;
            }
        }

        return $values ?: "";
    }

    /**
     * get where clause
     *
     * @param array $wheres
     * @return string
     */
    protected function getWhereClauses(array $wheres = []) : string
    {
        return "" . $this->getWheres($wheres)
            ->reduce(function ($carry, $where) {
                $value = $carry;
                $value .= $this->getNestedClauses($where);
                $value .= $this->getColumnClauses($where);
                $value .= $this->getRawClauses($where);
                $value .= $this->getInClauses($where);
                $value .= $this->getNotInClauses($where);
                $value .= $this->getOtherClauses($where, $carry);

                return $value;
            });
    }

    /**
     * get nested clauses
     *
     * @param array $where
     * @return string
     */
    protected function getNestedClauses(array $where) : string
    {
        if (! in_array($where["type"], ["Exists", "Nested", "NotExists"])) {
            return "";
        }

        return "-" . strtolower($where["type"]) . $this->getWhereClauses($where["query"]->wheres);
    }

    /**
     * get column clauses
     *
     * @param array $where
     * @return string
     */
    protected function getColumnClauses(array $where) : string
    {
        if ($where["type"] !== "Column") {
            return "";
        }

        return "-{$where["boolean"]}_{$where["first"]}_{$where["operator"]}_{$where["second"]}";
    }

    /**
     * get in clause
     *
     * @param array $where
     * @return string
     */
    protected function getInClauses(array $where) : string
    {
        if (! in_array($where["type"], ["In"])) {
            return "";
        }

        $this->currentBinding++;
        $values = $this->recursiveImplode($where["values"], "_");

        return "-{$where["column"]}_in{$values}";
    }

    /**
     * get not in clauses
     *
     * @param array $where
     * @return string
     */
    protected function getNotInClauses(array $where) : string
    {
        if (! in_array($where["type"], ["NotIn"])) {
            return "";
        }

        $this->currentBinding++;

        $values = $this->recursiveImplode($where["values"], "_");

        return "-{$where["column"]}_not_in{$values}";
    }

    /**
     * recursive implode
     *
     * @param array $items
     * @param string $glue
     * @return string
     */
    protected function recursiveImplode(array $items, string $glue = ",") : string
    {
        $result = "";

        foreach ($items as $value) {
            if (is_array($value)) {
                $result .= $this->recursiveImplode($value, $glue);

                continue;
            }

            $result .= $glue . $value;
        }

        return $result;
    }

    /**
     * get raw clauses
     *
     * @param array $where
     * @return string
     */
    protected function getRawClauses(array $where) : string
    {
        if ($where["type"] !== "raw") {
            return "";
        }

        $queryParts = explode("?", $where["sql"]);
        $clause = "_{$where["boolean"]}";

        while (count($queryParts) > 1) {
            $clause .= "_" . array_shift($queryParts);
            $clause .= $this->query->bindings["where"][$this->currentBinding];
            $this->currentBinding++;
        }

        $lastPart = array_shift($queryParts);

        if ($lastPart) {
            $clause .= "_" . $lastPart;
        }

        return "-" . str_replace(" ", "_", $clause);
    }

    /**
     * get other clauses
     *
     * @param array $where
     * @return string
     */
    protected function getOtherClauses(array $where) : string
    {
        if (in_array($where["type"], ["Exists", "Nested", "NotExists", "Column", "raw", "In", "NotIn"])) {
            return "";
        }

        $value = $this->getTypeClause($where);
        $value .= $this->getValuesClause($where);

        return "-{$where["column"]}_{$value}";
    }

    /**
     * get wheres
     *
     * @param array $wheres
     * @return Collection
     */
    protected function getWheres(array $wheres) : Collection
    {
        $wheres = collect($wheres);

        if ($wheres->isEmpty()) {
            $wheres = collect($this->query->wheres);
        }

        return $wheres;
    }

    /**
     * get with models
     *
     * @return string
     */
    protected function getWithModels() : string
    {
        $eagerLoads = collect($this->eagerLoad);

        if ($eagerLoads->isEmpty()) {
            return "";
        }

        return "-" . implode("-", $eagerLoads->keys()->toArray());
    }
}
