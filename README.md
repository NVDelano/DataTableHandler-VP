# DataTableHandler-VP


Add to controller.php 
```
    public function paginateIndex($request, $indexQuery, $withColumns = [], $returnPaginated = true)
    {
        $processedDataTable = DataTableService::process($request->query('lazyEvent'), $indexQuery, $withColumns, $returnPaginated);
        
        return $processedDataTable;
    }
```

use in any other controller
```
            $index = $this->paginateIndex($request, new Model(), ['relations'], true);
            return Response()->json($index);
```
