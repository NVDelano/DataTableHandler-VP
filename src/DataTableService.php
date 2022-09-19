<?php 

namespace Netvibes\Datatablehandler;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataTableService {
    static $baseTable;
    static $additionalSelect;
    static $searchType;
    static public function process($lazyQuery, $indexQuery, $withColumns, $returnPaginated)
    {
        // Setup
        $lazyEvent = null;
        if ($lazyQuery) {
            $lazyEvent = json_decode(urldecode($lazyQuery));
        }
        // Filtering
        self::$searchType = $lazyEvent->searchType ?? false;
        if (isset($lazyEvent->filters) && $lazyEvent->filters) {
            foreach ($lazyEvent->filters as $key => $filter) {
                if ($filter->value) {
                    if ($key == "global") {
                        $columns = isset($indexQuery->filters) ? $indexQuery->filters : [];
                        $indexQuery = self::setupWhere($indexQuery, $columns, $filter->value);
                    } else {
                        if (self::$searchType == 'regex') {
                            $indexQuery = $indexQuery->where($key, '~*', "\m($filter->value");
                        } else {
                            $indexQuery = $indexQuery->where($key, 'ILIKE', "%($filter->value%");
                        }
                    }
                }
            }
        }

        // With
        if ($withColumns && !empty($withColumns)) {
            $indexQuery = $indexQuery->with($withColumns);
        }

        self::$baseTable = $indexQuery->getModel()->getTable();
        
        // Select specific fields
        $selectingFields = false;
        if (isset($lazyEvent->selectFields) && $lazyEvent->selectFields && is_array($lazyEvent->selectFields)) {
            $indexQuery =  self::setupSelect($indexQuery, $lazyEvent->selectFields, $withColumns);
            $selectingFields = true;
        }

        // Sorting
        if (isset($lazyEvent->sortField) && $lazyEvent->sortField) {
            $sortOrder = $lazyEvent->sortOrder === 1 ? 'asc' : 'desc';
            $indexQuery = self::setupOrder($indexQuery, $lazyEvent->sortField, $sortOrder, $selectingFields);
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
    
    static public function setupOrder($indexQuery, $sortFields, $sortOrder, $selectingFields)
    {
        $sortFields = is_array($sortFields) ? $sortFields : [$sortFields];
        foreach ($sortFields as $sortField) {
            $sortRelation = explode('.', $sortField);
            $filterColumn = array_pop($sortRelation); // takes the last off, this is the column
            if (sizeof($sortRelation) >= 1) {
                // Joining the relations so we can filter on the end column
                    foreach ($sortRelation as $index => $relationName) {
                    if($index == 0) {
                        $previousRelation = self::$baseTable;
                    }
                    $relationNamePlural = Str::of($relationName)->plural()->snake();
                    if ($relationName === $relationNamePlural) {
                        break; // can't filter on Many-to-Many
                    }
                    $indexQuery = $indexQuery->leftJoin($relationNamePlural, $relationNamePlural.'.id', $previousRelation.'.'.$relationName.'_id');
                    $previousRelation = $relationNamePlural;
                }
                // select the base table and include the filter column that we joined
                self::$additionalSelect = $previousRelation.'.'.$filterColumn;
                if (!$selectingFields) {
                    $indexQuery = $indexQuery->select(self::$baseTable.'.*',$previousRelation.'.'.$filterColumn);
                }
                // relation filter
                $indexQuery = $indexQuery->orderBy($previousRelation.'.'.$filterColumn, $sortOrder);
            } else {
                // normal filter
                $indexQuery = $indexQuery->orderBy($sortField, $sortOrder);
            }
        }

        return $indexQuery;
    }

    static public function setupWhere($indexQuery, $columns, $filterValue, $relationName = []) {
        foreach ($columns as $columnKey => $column) {
            if (is_array($column)){
                $indexQuery = self::setupWhere($indexQuery, $column, $filterValue, array_merge($relationName, [$columnKey]));
            } else {
                if(in_array($column, ['created_at', 'updated_at', 'deleted_at'])){
                    continue;
                }
                if ($relationName === []) {
                    
                    if (self::$searchType == 'regex') {
                        $indexQuery = $indexQuery->orWhere($column, '~*', "\m$filterValue");
                    } else {
                        $indexQuery = $indexQuery->orWhere($column, 'ILIKE', "%$filterValue%");
                    }
                } else {
                    $indexQuery = $indexQuery->orWhereHas(implode('.', $relationName), function ($q) use ($column, $filterValue) {
                        if (self::$searchType == 'regex') {
                            $q->where($column, '~*', "\m$filterValue");
                        } else {
                            $q->where($column, 'ILIKE', "%$filterValue%");
                        }
                    });
                }
            }
        }

        return $indexQuery;
    }

    static public function setupSelect($indexQuery, $selectArray, $withColumns, $relation = null) {
        $relationArray = [];
        if($relation != null){
            $relationArray[$relation] = [];
        }

        foreach ($selectArray as $key => $select) {
            // dissalow fetching all
            if (is_string($select) && str_contains($select, '*')) {
                continue;
            }
            
            // If in array/object call setup with relation
            if(!is_string($select)) {
                $tempSelect = (Array) $select;
                $tempKey = array_key_first($tempSelect);
                $tempSelect = $tempSelect[$tempKey];
            
                $relationString = $relation != null ? $relation . '.' . $tempKey : $tempKey;
                $indexQuery = self::setupSelect($indexQuery, $tempSelect, $withColumns, $relationString);
            
                continue;
            }

            // If relation string exists
            if($relation != null){
                // If with is not allowed - skip
                if(!in_array($relation, $withColumns)){
                    continue;
                }

                // Fill the array for with's - select should be done in one with
                $relationArray[$relation][] = $select;
            } else {
                // Add normal select
                $indexQuery = $indexQuery->addSelect(self::$baseTable . '.' . $select);
            }
        }
        
        if(self::$additionalSelect){
            $indexQuery = $indexQuery->addSelect(self::$additionalSelect);
        }

        // Loop throught the with's array to add the select
        foreach ($relationArray as $key => $relation) {
            $indexQuery = $indexQuery->with([$key => function($q) use ($relation){
                $q->addSelect($relation);
            }]);
        }

        return $indexQuery;
    }

}
