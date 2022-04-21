<?php 

namespace src;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataTableService {
    static public function process($lazyQuery, $indexQuery, $withColumns, $returnPaginated)
    {
        // Setup
        $lazyEvent = null;
        if ($lazyQuery) {
            $lazyEvent = json_decode(urldecode($lazyQuery));
        }
        
        // Filtering
        if (isset($lazyEvent->filters) && $lazyEvent->filters) {
            foreach ($lazyEvent->filters as $key => $filter) {
                if ($filter->value) {
                    if ($key == "global"){
                        $columns = isset($indexQuery->filters) ? $indexQuery->filters : [];
                        $indexQuery = self::setupWhere($indexQuery, $columns, $filter->value);
                    } else {
                        $indexQuery = $indexQuery->where($key, 'ILIKE', "%{$filter->value}%");
                    }
                }
            }
        }

        // With
        if ($withColumns && !empty($withColumns)) {
            $indexQuery = $indexQuery->with($withColumns);
        }

        // Sorting
        if (isset($lazyEvent->sortField) && $lazyEvent->sortField) {
            $sortOrder = $lazyEvent->sortOrder === 1 ? 'asc' : 'desc';
            $sortRelation = explode('.', $lazyEvent->sortField);
            $filterColumn = array_pop($sortRelation); // takes the last off, this is the column
            if (sizeof($sortRelation) >= 1) {
                $baseTable = $indexQuery->first()->getTable();
                // Joining the relations so we can filter on the end column
                    foreach ($sortRelation as $index => $relationName) {
                    if($index == 0) {
                        $previousRelation = $baseTable;
                    }
                    $relationNamePlural = Str::of($relationName)->plural()->snake();
                    if ($relationName === $relationNamePlural) {
                        break; // can't filter on Many-to-Many
                    }
                    $indexQuery = $indexQuery->leftJoin($relationNamePlural, $relationNamePlural.'.id', $previousRelation.'.'.$relationName.'_id');
                    $previousRelation = $relationNamePlural;
                }
                // select the base table and include the filter column that we joined
                $indexQuery = $indexQuery->select($baseTable.'.*',$previousRelation.'.'.$filterColumn);
                // relation filter
                $indexQuery = $indexQuery->orderBy($previousRelation.'.'.$filterColumn, $sortOrder);
            } else {
                // normal filter
                $indexQuery = $indexQuery->orderBy($lazyEvent->sortField, $sortOrder);
            }
        } else {
            // default filter
            $indexQuery =  $indexQuery->orderBy('created_at', 'desc');
        }

        // Pagination
        $paginateRows = isset($lazyEvent->rows) ? $lazyEvent->rows : 30;
        
        
        // Return UN-paginated results
        if (!$returnPaginated) {
            return ['query' => $indexQuery, 'pages' => $paginateRows];
        }


        $paginatedIndex = $indexQuery->paginate($paginateRows);

        // Return paginated results
        return $paginatedIndex;
    }

    static public function setupWhere($indexQuery, $columns, $filterValue, $relationName = []) {
        foreach ($columns as $columnKey => $column) {
            if (is_array($column)){
                $indexQuery = self::setupWhere($indexQuery, $column, $filterValue, array_merge($relationName, [$columnKey]));
            } else {
                if ($relationName === []) {
                    $indexQuery = $indexQuery->orWhere($column, 'ILIKE', "%{$filterValue}%");
                } else {
                    $indexQuery = $indexQuery->orWhereHas(implode('.', $relationName), function ($q) use ($column, $filterValue) {
                        $q->where($column, 'ILIKE', "%{$filterValue}%");
                    });
                }
            }
        }

        return $indexQuery;
    }
}