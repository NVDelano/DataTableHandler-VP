<?php 

namespace Netvibes\Datatablehandler;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataTableService {
    static $baseTable;
    static $additionalSelect;
    static $searchType;
    static public function process($lazyQuery, $indexQuery, $withColumns, $returnPaginated, $officeCheck = false, $withCounts = [])
    {
        // Setup
        $lazyEvent = null;
        if ($lazyQuery) {
            $lazyEvent = json_decode(urldecode($lazyQuery));
        }

        self::$baseTable = $indexQuery->getModel()->getTable();

        // Filtering
        $columns = isset($indexQuery->filters) ? $indexQuery->filters : [];
        if ($officeCheck) {
            $indexQuery = $indexQuery->where('office_id', \Auth::user()->office_id);
        }
        if (isset($lazyEvent->filters) && $lazyEvent->filters) {
            foreach ($lazyEvent->filters as $key => $filter) {
                if ($filter->value) {
                    $filterValue = htmlspecialchars($filter->value);
                    if ($key == "global") {
                        $indexQuery = $indexQuery->where(function($query) use ($columns, $filterValue){
                            $indexQuery = self::setupWhere($query, $columns, $filterValue);
                        });
                    } else {
                        $indexQuery = $indexQuery->whereRaw($key." like '%".$filterValue."%'");
                    }
                }
            }
        }
        
        if (isset($lazyEvent->filterData) && $lazyEvent->filterData) {
            foreach ($lazyEvent->filterData as $filterData) {
                if(strpos( $filterData->field, '.' ) !== false ) {
                    $breakIndex = strrpos($filterData->field, '.');
                    $from = substr($filterData->field, 0, $breakIndex);
                    $field = substr($filterData->field, $breakIndex + 1);
                    $indexQuery = $indexQuery->whereHas($from, function($q) use ($field, $filterData){
                        self::setupFilters($q, $field, $filterData->value);
                    });
                }
                else{
                    $indexQuery = self::setupFilters($indexQuery, $filterData->field, $filterData->value, self::$baseTable);
                }
            }
        }

        // With
        if ($withColumns && !empty($withColumns)) {
            $indexQuery = $indexQuery->with($withColumns);
        }
        
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
                    $excludePluralArray = ['job_booking_voyage'];
                    if (in_array($relationName, $excludePluralArray)) {
                        $relationNamePlural = Str::of($relationName)->snake();
                    } else {
                        $relationNamePlural = Str::of($relationName)->plural()->snake();
                        if ($relationName === $relationNamePlural) {
                            break; // can't filter on Many-to-Many
                        }
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
                $indexQuery = $indexQuery->orderByRaw(self::$baseTable . '.' . $sortField . ' ' . $sortOrder);
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
                    $indexQuery = $indexQuery->orWhereRaw(self::$baseTable . '.' . $column." like '%".$filterValue."%'");
                } else {
                    $indexQuery = $indexQuery->orWhereHas(implode('.', $relationName), function ($q) use ($column, $filterValue) {
                        $q->whereRaw($column." like '%".$filterValue."%'");
                    });
                }
            }
        }

        return $indexQuery;
    }

    static public function setupFilters($indexQuery, $field, $value, $table = null) {
        if (is_array($value)) {
            $indexQuery = $indexQuery->whereIn($field, $value);
        } else if ($table && ($value === "NULL" || $value === "NOT NULL")) {
            $completeFieldName = $table ? $table . '.' . $field : $field;
            $indexQuery = $indexQuery->whereRaw($completeFieldName . ' IS ' . $value);
        } else if ($value === "NULL") {
            $indexQuery = $indexQuery->whereNull($field);
        } else if ($value === "NOT NULL") {
            $indexQuery = $indexQuery->whereNotNull($field);
        } else if (is_string($value) && substr($value, 0, 4) === "NOT!") {
            $indexQuery = $indexQuery->where($field, "!=", substr($value, 4));
        } else if (is_string($value) && substr($value, 0, 5) === "LIKE!") {
            $indexQuery = $indexQuery->where($field, "like", "%".substr($value, 5)."%");
        } else {
            $completeFieldName = $table ? $table . '.' . $field : $field;
            $indexQuery = $indexQuery->whereRaw($completeFieldName . ' = ' . $value);
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
