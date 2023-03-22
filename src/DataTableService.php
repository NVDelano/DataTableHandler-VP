<?php 

namespace Netvibes\Datatablehandler;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataTableService {
    static $baseTable;
    static $additionalSelect;
    static $searchType;
    static public function process($lazyQuery, $indexQuery, $withColumns, $returnPaginated, $officeCheck = false)
    {
        // Setup
        $lazyEvent = null;
        if ($lazyQuery) {
            $lazyEvent = json_decode(urldecode($lazyQuery));
        }
        // Filtering
        $columns = isset($indexQuery->filters) ? $indexQuery->filters : [];
        if ($officeCheck) {
            $indexQuery = $indexQuery->where('office_id', \Auth::user()->office_id);
        }
        if (isset($lazyEvent->filters) && $lazyEvent->filters) {
            foreach ($lazyEvent->filters as $key => $filter) {
                if ($filter->value) {
                    $filterValue = $filter->value;
                    if ($key == "global") {
                        $indexQuery = $indexQuery->where(function($query) use ($columns, $filterValue){
                            $indexQuery = self::setupWhere($query, $columns, $filterValue);
                        });
                    } else {
                        $indexQuery = $indexQuery->whereRaw("unaccent(cast(".$key." as varchar)) ilike unaccent('%".$filterValue."%')");
                    }
                }
            }
        }
        
        if (isset($lazyEvent->filterdata) && $lazyEvent->filterdata) {
            foreach ($lazyEvent->filterdata as $filterdata) {
                if(strpos( $filterdata->field, '.' ) !== false ) {
                    continue; // TODO support relations
                }
                $indexQuery = $indexQuery->whereIn($filterdata->field, $filterdata->value);
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
    
    // setupSort
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
                    $idName = explode('~', $relationName);
                    $relationName = $idName[0] ?? $relationName;
                    $relationNamePlural = Str::of($relationName)->plural()->snake();
                    if ($relationName === $relationNamePlural) {
                        break; // can't filter on Many-to-Many
                    }
                    $idName = $idName[1] ?? $relationName;
                    $indexQuery = $indexQuery->leftJoin($relationNamePlural, $relationNamePlural.'.id', $previousRelation.'.'.$idName.'_id');
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
                    $indexQuery = $indexQuery->orWhereRaw("unaccent(cast(".$column." as varchar)) ilike unaccent('%".$filterValue."%')");
                } else {
                    $indexQuery = $indexQuery->orWhereHas(implode('.', $relationName), function ($q) use ($column, $filterValue) {
                        $q->whereRaw("unaccent(cast(".$column." as varchar)) ilike unaccent('%".$filterValue."%')");
                    });
                }
            }
        }

        return $indexQuery;
    }

    static public function setupSelect($indexQuery, $selectArray, $withColumns, $relation = null) {
        // Splits the with relations and adds these to the $withColumns so we can select for each relation
        foreach ($withColumns as $withColumn) {
            if(!is_string($withColumn)){
                continue;
            }
            $explodedColumns = explode('.', $withColumn);
            $previousColumns = '';
            foreach ($explodedColumns > 1 ? $explodedColumns : [] as $eindex => $explodedColumn) {
                if (!isset($explodedColumns[$eindex+1])) {
                    break;
                } else {
                    array_push($withColumns, $previousColumns . $explodedColumn);
                    $previousColumns .= "{$explodedColumn}.";
                }
            }
        }

        $relationArray = [];
        if($relation != null){
            $keyParts = explode('.', $relation);
            $previousKeyPart = '';
            foreach ($keyParts as $keypart) {
                $relationArray[$previousKeyPart . $keypart] = [];
                $previousKeyPart .= $keypart . '.';
            }
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
