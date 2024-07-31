# MongoDB

`\gcgov\framework\services\mongodb`

## Usage

You will primarily interact with this service through extended classes that model your data structure. Data classes
will extend `\gcgov\framework\services\mongodb\model` or `\gcgov\framework\services\mongodb\embedded`.

## Model Objects

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
	
	#[label( 'Locations' )]
	/** @var \app\models\component\address[] $address */
	public array $addresses = [];
}
```

In Mongo, the documents in the `inspection` collection will look like:

```json
{
	"_id": '66aa42df57b1bd608017dbf5',
	"projectId": '66aa42e3582cbf0763728468',
	"inspectionNumber": 0,
	"addresses": []
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
\app\models\inspection::save( object &$object, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::saveMany( array &$objects, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null )
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

`$pipeline` is an associative array specifying
an [aggregation pipeline operation](https://www.mongodb.com/docs/php-library/current/reference/method/MongoDBCollection-aggregate/)

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


<details>
    <summary><b>Save One</b></summary>

Method will update or insert the provided object into the collection and will
return `gcgov\framework\services\mongodb\updateDeleteResult` that reveals details about the database actions performed.

`$object` is the model to save. It is passed by reference so any changes made to the object as a result of the save
operation will be available in the same object after the save is completed.

`$upsert` defaults to true. Can be set to false to only allow updating existing records

`$callBeforeAfterHooks` defaults to true. Can be set to false to disable automatic calling of  `_beforeSave( &$object )`
and `_afterSave( &$object )` on the model and

`$mongoDbSession` is null by default but a `\MongoDB\Driver\Session` can be provided in order to perform a transaction
of multiple model operations across one or many collections.

```php
\app\models\inspection::save( object &$object, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult
```

Example: default save operation for `\app\models\inspection` object

```php
$updateResult = \app\models\inspection::save( $inspection );
```

Example: save `\app\models\inspection` object as part of a transaction

```php
$transactionSession = \gcgov\framework\services\mongodb\tools\mdb::startSessionTransaction();
try {
    \app\models\structure::save( $structure, true, true, $transactionSession );
    \app\models\inspection::save( $inspection, true, true, $transactionSession );
    $transactionSession->commitTransaction();
    $transactionSession->endSession();
}
catch( modelException $e ) {
    if( $transactionSession->isInTransaction() ) {
        $transactionSession->abortTransaction();
    }
}
```

</details>


<details>
    <summary><b>Save Many</b></summary>

Method will update or insert the provided objects into the collection and will return and array
of `gcgov\framework\services\mongodb\updateDeleteResult` that reveals details about the database actions performed.

`$object` is the model to save. It is passed by reference so any changes made to the object as a result of the save
operation will be available in the same object after the save is completed.

`$upsert` defaults to true. Can be set to false to only allow updating existing records

`$callBeforeAfterHooks` defaults to true. Can be set to false to disable automatic calling of  `_beforeSave( &$object )`
and `_afterSave( &$object )` on the model and

`$mongoDbSession` is null by default but a `\MongoDB\Driver\Session` can be provided in order to perform a transaction
of multiple model operations across one or many collections.

```php
\app\models\inspection::saveMany( array &$objects, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult[]
```

Example: default save operation for many `\app\models\inspection` objects

```php
$updateResults = \app\models\inspection::saveMany( $inspections );
```

Example: save many `\app\models\inspection` objects as part of a transaction

```php
$transactionSession = \gcgov\framework\services\mongodb\tools\mdb::startSessionTransaction();
try {
    \app\models\structure::saveMany( $structures, true, true, $transactionSession );
    \app\models\inspection::saveMany( $inspections, true, true, $transactionSession );
    $transactionSession->commitTransaction();
    $transactionSession->endSession();
}
catch( modelException $e ) {
    if( $transactionSession->isInTransaction() ) {
        $transactionSession->abortTransaction();
    }
}
```

</details>

<details>
    <summary><b>Delete One</b></summary>

Method will delete the provided object in the collection and will
return `gcgov\framework\services\mongodb\updateDeleteResult` that reveals details about the database actions performed.

`$mongoDbSession` is null by default but a `\MongoDB\Driver\Session` can be provided in order to perform a transaction
of multiple model operations across one or many collections.

```php
\app\models\inspection::delete( \MongoDB\BSON\ObjectId|string $_id, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult
```

Example: default delete operation for a `\app\models\inspection` model

```php
$updateResult = \app\models\inspection::delete( '66aa2d805b4ad858460f12b7' );
```

Example: delete `\app\models\inspection` object as part of a transaction

```php
$transactionSession = \gcgov\framework\services\mongodb\tools\mdb::startSessionTransaction();
try {
    \app\models\structure::save( $structure, true, true, $transactionSession );
    \app\models\inspection::delete( '66aa2d805b4ad858460f12b7', $transactionSession );
    $transactionSession->commitTransaction();
    $transactionSession->endSession();
}
catch( modelException $e ) {
    if( $transactionSession->isInTransaction() ) {
        $transactionSession->abortTransaction();
    }
}
```

</details>


<details>
    <summary><b>Delete Many</b></summary>

Method will update or insert the provided objects into the collection and will return and array
of `gcgov\framework\services\mongodb\updateDeleteResult` that reveals details about the database actions performed.

`$itemsToDelete` is an array of the model objects to delete

`$mongoDbSession` is null by default but a `\MongoDB\Driver\Session` can be provided in order to perform a transaction
of multiple model operations across one or many collections.

```php
\app\models\inspection::deleteMany( array $itemsToDelete, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult[]
```

Example: default delete operation for many `\app\models\inspection` objects

```php
$updateResults = \app\models\inspection::deleteMany( $inspections );
```

Example: delete many `\app\models\inspection` objects as part of a transaction

```php
$transactionSession = \gcgov\framework\services\mongodb\tools\mdb::startSessionTransaction();
try {
    \app\models\structure::save( $structure, true, true, $transactionSession );
    \app\models\inspection::deleteMany( $inspections, $transactionSession );
    $transactionSession->commitTransaction();
    $transactionSession->endSession();
}
catch( modelException $e ) {
    if( $transactionSession->isInTransaction() ) {
        $transactionSession->abortTransaction();
    }
}
```

</details>

## Embedded Objects

A class that extends `\gcgov\framework\services\mongodb\embedded` is a representation of an embedded document that can
be embedded in any model class.

Embedded objects do not have any required fields but it is best practice to include
an Object Id as `$_id` if the embedded object will be embedded in an array in a model field. When embedded as an array,
the model *must* use a PHPDoc comment to set the type. Without a PHPDoc comment to define the array type, the array will
not only be deserialized to the embedded type if `__pclass` is stored in the database. `__pclass` deserialization should
not be relied on.

Embedded objects **cannot** be saved as top level documents in a collection. If you wish to both embed an object and
store it in its own collection, it must be defined as a model. There are important caveats to this approach that you
must be aware of to correctly handle saving embedded models (TODO: add section about how to manage this - save embedded
item to update collection and embedded object vs saving the parent object won't cascade update).

```php
namespace \app\models\component\address;

class address extends \gcgov\framework\services\mongodb\embeddable {

	public \MongoDB\BSON\ObjectId          $_id;

	#[label( 'Address Type' )]
	public string                          $type        = 'mailing';

	#[label( 'Address' )]
	public string                          $address     = '';

	#[label( 'Apt/Suite' )]
	public string                          $address2    = '';

	#[label( 'City' )]
	public string                          $city        = '';

	#[label( 'State' )]
	public string                          $state       = '';

	#[label( 'Zip' )]
	public string                          $zip         = '';

	#[label( 'Address Type List' )]
	#[excludeJsonDeserialize]
	#[excludeBsonUnserialize]
	#[excludeBsonSerialize]
	/** @var string[] $_validTypes */
	public array                           $_validTypes = [
		'mailing'  => 'Mailing',
		'physical' => 'Physical'
	];
```

## Class Attributes
### #[includeMeta]
When serialized to JSON, model and embedded classes will automatically include a `_meta` field. To disable this for a 
specific model or embedded class, give the class the `#[includeMeta(false)]` attribute

## Property Attributes

Model and embedded object properties may utilize attributes to customize functionality of a property or add meta data
about the field.

### #[excludeBsonSerialize]
Properties tagged with `#[excludeBsonSerialize]` will not be saved in the database

### #[excludeBsonUnserialize]
Properties tagged with `#[excludeBsonUnserialize]` will be excluded when deserializing the document from the database. 
The resulting object will have the default value for the property regardless of what value is saved in the database for 
the property. 

### #[excludeJsonSerialize]
Properties tagged with `#[excludeJsonSerialize]` will be excluded from the output when serializing the object to json 

### #[excludeJsonDeserialize]
Properties tagged with `#[excludeJsonDeserialize]` will be excluded when deserializing the object from JSON to object.
The resulting object will have the default value for the property regardless of what value was set in the JSON string.


```php 
use gcgov\framework\models\customConstraints as CustomAssert;
use Symfony\Component\Validator\Constraints as Assert;

use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize;
#[label( string $label )]
#[autoIncrement]
#[deleteCascade]
#[excludeFromTypemapWhenThisClassNotRoot]
#[foreignKey(string $embeddedPropertyName, array $embeddedObjectFilter = [] )]
#[redact( array $redactIfUserHasAnyRoles=[], array $redactIfUserHasAllRoles=[] )]
#[visibility(bool $default = true, array $groups = [], bool $valueIsVisibilityGroup = false)]

```
