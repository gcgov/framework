# MongoDB

`\gcgov\framework\services\mongodb`

## Usage

You will primarily interact with this service through extended classes that model your data structure. Data classes
will extend `\gcgov\framework\services\mongodb\model` or `\gcgov\framework\services\mongodb\embedded`.

## Models

A class that extends `\gcgov\framework\services\mongodb\model` is a representation of the documents in a Mongo
collection. Every model *must* include a public field `$_id` of type `\MongoDB\BSON\ObjectId` that will serve as the
'primary key' for the collection.

### Example

Example: This `inspection` model defines the fields and types that documents stored in the `inspection` collection in
Mongo. All examples assume this is the model.

```php 
final class inspection extends \gcgov\framework\services\mongodb\model {

	const _COLLECTION = 'inspection';
	const _HUMAN = 'inspection';
	const _HUMAN_PLURAL = 'inspections';

	#[label( 'Id' )]
	public \MongoDB\BSON\ObjectId $_id;

	#[label( 'Project Id' )]
	public \MongoDB\BSON\ObjectId|null $projectId = null;

	#[label( 'Inspection Number' )]
	#[autoIncrement]
	public int $inspectionNumber = 0;
}
```

In Mongo, the documents in the `inspection` collection will look like:

```json
{
	"_id": ObjectId(),
	"projectId": ObjectId()|null,
	"inspectionNumber": 0
}
```

### Factory Methods

Extending `\gcgov\framework\services\mongodb\model` provides static methods for storing and retrieving documents from
Mongo.

```php
\app\models\inspection::getAll( array $filter = [], array $sort = [], array $options = [] )
\app\models\inspection::getPagedResponse( int|string|null $limit, int|string|null $page, array $filter = [], array $options = [] )
\app\models\inspection::getOne( \MongoDB\BSON\ObjectId|string $_id )
\app\models\inspection::getOneBy( array $filter = [], array $options = [] )
\app\models\inspection::aggregation( array $pipeline = [], $options = [] )
\app\models\inspection::saveMany( array &$objects, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::save( object &$object, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::delete( \MongoDB\BSON\ObjectId|string $_id, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::deleteMany( array $itemsToDelete, ?\MongoDB\Driver\Session $mongoDbSession = null )
```

<details>
    <summary><b>Get All</b></summary>

Method will return an array of you model class with all matching documents in the collection.
`$options` is an associative array specifying the desired options as provided in the Mongo
library [See options](https://www.mongodb.com/docs/php-library/current/reference/method/MongoDBCollection-find/)
Typemap is automatically added to the options based on the type definitions of the model

```php 
\app\models\inspection::getAll( array $filter = [], array $sort = [], array $options = [] )
```

Example: Return all records in the collection. *Caution* - providing no filter can be memory intensive and lengthy.

```php 
$inspections = \app\models\inspection::getAll()
```

Example: Return matching records in the collection, sorted by field 'inspectionNumber' in ascending order

```php 
$inspections = \app\models\inspection::getAll([ 'inspectionNumber'=>['$gt'=>10] ], [ 'inspectionNumber'=>1 ])
```

</details>

<details>
    <summary><b>Get All Paged</b></summary>

Method will return an instance of `\gcgov\framework\services\mongodb\getResult` where:

`$result->getData()` is array of you model class with all matching documents in the collection

`$result->getLimit()` is the maximum number of documents per page

`$result->getPage()` is the current page the results represent

`$result->getSkip()` is the number of documents to skip to get to the first document on this page

`$result->getTotalDocmentCount()` is the grand total number of documents that match the provided filter 


`$options` is an associative array specifying the desired options as provided in the Mongo
library [See options](https://www.mongodb.com/docs/php-library/current/reference/method/MongoDBCollection-find/)
Typemap is automatically added to the options based on the type definitions of the model

```php 
\app\models\inspection::getPagedResponse( int|string|null $limit, int|string|null $page, array $filter = [], array $options = [] )
```

Example: Return the first page of up to 10 documents that match the provided filter

```php 
\app\models\inspection::getPagedResponse( 10, 1, [ 'inspectionNumber'=>['$gt'=>10] ] )
```


</details>


<details>
    <summary><b>Get One</b></summary>

Method will return the matching document in the collection.

```php
\app\models\inspection::getOne( \MongoDB\BSON\ObjectId|string $_id )
```

Example: return one document from the collection

```php
$inspection = \app\models\inspection::getOne( '66aa2d805b4ad858460f12b7' )
```

</details>


<details>
    <summary><b>Get One By Filter</b></summary>

Method will return the first document in collection that matched the filter. `$options` is an associative array
specifying the desired options as provided in the Mongo
library [See options](https://www.mongodb.com/docs/php-library/current/reference/method/MongoDBCollection-findOne/)
Typemap is automatically added to the options based on the type definitions of the model

```php
\app\models\inspection::getOneBy( array $filter = [], array $options = [] )
```

Example: return one document from the collection

```php
$inspection = \app\models\inspection::getOneBy( ['inspectionNumber'=>9] )
```

</details>


<details>
    <summary><b>Aggregation Pipeline</b></summary>

Method will return an array of objects produced by the aggregation pipeline. 

`$pipeline` is an associative array specifying an [aggregation pipeline operation](https://www.mongodb.com/docs/php-library/current/reference/method/MongoDBCollection-aggregate/)

`$options` is an associative array specifying the desired options as provided in the Mongo
library [See options](https://www.mongodb.com/docs/php-library/current/reference/method/MongoDBCollection-aggregate/)
**NOTE**: typemap is **not** defined automatically because the pipeline may generate a document that does not match the 
model class. All models use persistence from the Mongo library which adds `__pclass` to each document in the collection
that saves the document type. Documents defined in the pipeline output that include the `__pclass` field, the returned 
documents *will* be typecast during deserialization.

```php
\app\models\inspection::aggregation( array $pipeline = [], $options = [] )
```

Example: return an array of inspection models with an added field named 'createdDate', set from a matching project

```php
$inspections = \app\models\inspection::aggregation([
    [
        '$match' => [
            'inspectionNumber' => ['$gt'=>10]
        ]
    ],
    [
        '$lookup' => [
            'from' => 'project',
            'localField' => 'projectId',
            'foreignField' => '_id',
            'as' => 'projects'
        ]
    ],
    [
        '$unwind' => [
            'path' => '$projects',
            'preserveNullAndEmptyArrays' => false
        ]
    ],
    [
        '$addFields' => [
            'createdDate' => '$projects.applicationDate'
        ]
    ]
]);
```

</details>

## Attributes

```php 
#[label( 'Inspection Number' )]
#[autoIncrement]
```
