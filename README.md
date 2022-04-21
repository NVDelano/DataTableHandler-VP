# DataTableHandler-VP

## Installation
Add to controller.php 
```
    public function paginateIndex($request, $indexQuery, $withColumns = [], $returnPaginated = true)
    {
        $processedDataTable = DataTableService::process($request->query('lazyEvent'), $indexQuery, $withColumns, $returnPaginated);
        
        return $processedDataTable;
    }
```

Add to your Models to allow global filtering on these columns / relation->columns
```
public $filters = [
    'column1',
    'column2',
    'relation' => [
        'column1'
    ]
];
```
Sorting is not possible on many-to-many or one-to-many relations

## Usage
use in any other controller
```
            $index = $this->paginateIndex($request, new Model(), ['relations'], true);
            return Response()->json($index);
```

### URL params:

```
? page = 1
& lazyEvent = {
   "first":0,
   "rows":10,
   "sortField":"order",
   "sortOrder":1,
   "filters":{
      "global":{
         "value":null,
         "matchMode":"contains"
      }
   }
}
```
