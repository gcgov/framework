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
must be aware of to correctly handle saving embedded models (see 'Attributes for Embedding Models' section).

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

## Attributes

Model and embedded object properties may utilize attributes to customize functionality of a property or add meta data
about the field.

### #[includeMeta]

Applied to classes. When serialized to JSON, model and embedded classes will automatically include a `_meta` field. To
disable this for a specific model or embedded class, give the class the `#[includeMeta(false)]` attribute

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

### #[label('Human Readable Label')]

Properties tagged with `#[label(string $label)]` will be added the object's `_meta` output in the labels section and the
fields section.

Example embedded class with label:

```php 
class address extends \gcgov\framework\services\mongodb\embeddable {

	public \MongoDB\BSON\ObjectId          $_id;

	#[label( 'Address Type' )]
	public string                          $type        = 'mailing';
}
```

After JSON serialization:

```json
{
	"_id": "",
	"type": "mailing",
	"_meta": {
		...
		"fields": {
			"type": {
				"label": "Address Type",
				"error": false,
				"errorMessages": [],
				"success": false,
				"successMessages": [],
				"hints": [],
				"state": "",
				"required": false,
				"visible": true,
				"valueIsVisibilityGroup": false,
				"visibilityGroups": [],
				"validating": false
			}
		}
	}
}
```

### #[autoIncrement()]

Properties tagged with `#[autoIncrement]` will automatically increment upon insert into the database. With no parameters
provided, the value will be set to the previous maximum value + 1.

#### Basic example:

```php 
final class inspection extends \gcgov\framework\services\mongodb\model {
	...
	#[label( 'Inspection Number' )]
	#[autoIncrement]
	public int $inspectionNumber = 0;
	
	...
}
```

1. The first document inserted into the `inspection` collection will have `$inspectionNumber` set to 1 (previous max of
   0 + 1).
1. The second document inserted into the `inspection` collection will have `$inspectionNumber` set to 2 (previous max of
   1 + 1).
1. The third document inserted into the `inspection` collection will have `$inspectionNumber` set to 3 (previous max of
   2 + 1).

#### Advanced Example (Grouping and Formatting)

By default, one property in a collection will have an ever-increasing automatic value. You can, however, create groups
of incrementing numbers within a collection. Grouping allows one collection to have multiple automatic incrementing
values that increment at different rates.

In addition, auto incremented values may be formatted by providing a method name to the `countFormatMethod` parameter.

In this example, documents in the `project` collection will have `$projectReferenceNumber` set to a formatted increasing
value within the group provided.

```php 
final class project extends \gcgov\framework\services\mongodb\model {
	...
	#[label( 'Project Reference Number' )]
	#[autoIncrement( groupByMethodName: 'getProjectNumberGroup', countFormatMethod: 'formatProjectIncrementer' )]
	public string $projectReferenceNumber = '';
	
	...
	
	public function getProjectNumberGroup(): string {
		if( $this->projectType==='fireMarshal' ) {
			return 'FM';
		}
		elseif( $this->projectType==='stormwater' ) {
			return 'SW';
		}

		//default by calendar year
		return (string)$this->applicationDate->format( 'Y' );
	}
	
	public function formatProjectIncrementer( int $count ): string {
		return $this->getProjectNumberGroup() . '-' . str_pad( $count, 4, '0', STR_PAD_LEFT );
	}

}
```

1. The first document inserted into the `project` collection with field `projectType` set to `fireMarshal` will
   have `$projectReferenceNumber` set to FM-0001 (group FM previous max of 0 + 1).
1. The second document inserted into the `project` collection with field `projectType` set to `fireMarshal` will
   have `$projectReferenceNumber` set to FM-0002 (group FM previous max of 1 + 2).
1. The first document inserted into the `project` collection with field `applicationDate` in 2024 and
   field `projectType` set to `permits` will have `$projectReferenceNumber` set to 2024-0001 (group 2024 previous max of
   0 + 1).
1. The second document inserted into the `project` collection with field `applicationDate` in 2024 and
   field `projectType` set to `permits` will have `$projectReferenceNumber` set to 2024-0002 (group 2024 previous max of
   1 + 1).

### #[redact()]

Properties tagged with `#[redact( array $redactIfUserHasAnyRoles=[], array $redactIfUserHasAllRoles=[] )]` will be
removed from the JSON output of the system when the provided conditions are met. This is useful for allowing a role to
read from a collection but to hide certain properties from the user because of their permission roles.

Example: users with the role `constants::ROLE_PUBLIC_WEB` will not see a phone property on the JSON object returned to
them.

```php
final class project extends \gcgov\framework\services\mongodb\model {
	...
	#[label( 'Phone' )]
	#[redact( [ constants::ROLE_PUBLIC_WEB ] )]
	public \app\models\component\phone $phone
	...
}
```

### #[visibility()]

Properties tagged with `#[visibility(bool $default = true, array $groups = [], bool $valueIsVisibilityGroup = false)]`
will impact the output of `_meta.fields.{field-name}.visible`, `_meta.fields.{field-name}.valueIsVisibilityGroup`, and
`_meta.fields.{field-name}.visibilityGroups`. This will only ever set the default state. Javascript must be used on the
UI to respect the visibility of fields and to respond to changes in the `visibilityGroups` so that field visibility is
updated as other values on the object are modified.

Example:

```php
final class project extends \gcgov\framework\services\mongodb\model {
	...
	#[label( 'Status' )]
	#[visibility( default: true, valueIsVisibilityGroup: true )]
	public ?\app\models\keyValueItem $status = null;

	#[label( 'Application Date' )]
	#[visibility( default: true )]
	public \DateTimeImmutable $applicationDate;

	#[label( 'Submitted Date' )]
	#[visibility( default: false, groups: [ constants::EXTERNAL_STATUS_ID_SUBMITTED ] )]
	public ?\DateTimeImmutable $submittedDate = null;
	...
}
```

See Permits API/App externalWebRequests for full example in use 

## Attributes for Embedding Models

These attributes are only relevant on properties that embed other top level *models*.

### #[deleteCascade]

When an object with a property tagged with `#[deleteCascade]` is deleted, all instances of the child item are also
deleted in its own collection and anywhere else it is embedded.

Example:

```php 
final class project extends \gcgov\framework\services\mongodb\model {
	...
	#[deleteCascade]
	public ?\app\model\inspection $inspection = null;
	...
}
```

When `\app\models\project::delete( $projectId )` is called, the inspection saved in `$inspection` will be removed from
the inspection collection and removed from all other documents that it is embedded in.

### #[excludeFromTypemapWhenThisClassNotRoot]

It is possible to create an infinite loop by nesting models. To the extent possible, you should avoid a nesting loop of
models.

An example would be if a `project` embeds an `inspection` but `inspection` also includes `project` as a property
that isn't saved to the database but is used for aggregation. This would cause an infinite loop because as the typemap
is resolved for `project`, `inspection` is resolved which requires `project` to be resolved, which requires `inspection`
to be resolved, etc.

To escape this trap, tag the nested property (`inspection.project` in this example) with
`#[excludeFromTypemapWhenThisClassNotRoot]` to exclude it from the typemap resolution. The caveat to this is that
`$inspection->project` *may* not be typed as `project`. In these cases we are relying on `__pclass` automatic
typecasting.

### #[foreignKey()]

Properties tagged with `#[foreignKey()]` must be typed arrays with a model type. The purpose is to enable insertions
of the foreign model in the foreign collection to automatically insert into the typed array as well.

**Basic Example:**

The first parameter of `#[foreignKey(string $embeddedPropertyName, array $embeddedObjectFilter = [] )]` must match the
field name in the embedded model that will match the `$_id` of the parent model.

In this example, when `\app\model\transaction::save( $transaction )` is called, the transaction will be stored in the
`transaction` collection and will also be inserted into documents in the `project` collection in the `transactions`
field where `project._id` matches `transaction.projectId`.

```php 
final class project extends \gcgov\framework\services\mongodb\model {
	...
	#[label( 'Transactions' )]
	#[foreignKey( 'projectId' )]
	/** @var \app\models\transaction[] $transactions */
	public array $transactions = [];
	...
}

final class transaction extends \gcgov\framework\services\mongodb\model {
	...
	public \MongoDB\BSON\ObjectId $_id;
	
	#[label( 'Project Id' )]
	public ?\MongoDB\BSON\ObjectId $projectId = null;
	...
}
```

**Advanced Example:**

The second parameter of `#[foreignKey(string $embeddedPropertyName, array $embeddedObjectFilter = [] )]` is an optional
filter to limit what documents are embedded automatically. The `$embeddedObjectFilter` should be a standard Mongo find
array filter that is used to filter the embedded model collection to further restrict which documents will be embedded
on insert. In this example, only documents where the `transaction.projectId` matches `project._id` and where
`transaction.recurringTemplate` is `false` will be embedded.

```php 
final class project extends \gcgov\framework\services\mongodb\model {
	...
	#[label( 'Transactions' )]
	#[foreignKey( 'projectId', [ 'recurringTemplate' => false ] )]
	/** @var \app\models\transaction[] $transactions */
	public array $transactions = [];
	...
}

final class transaction extends \gcgov\framework\services\mongodb\model {
	...
	public \MongoDB\BSON\ObjectId $_id;
	
	#[label( 'Project Id' )]
	public ?\MongoDB\BSON\ObjectId $projectId = null;
	
	#[label( 'Is Recurring Template' )]
	public bool $recurringTemplate = null;
	
	...
}
```

## Validation Attributes
See [Symfony Validation](https://symfony.com/doc/current/validation.html) for all Assert options and 
[Validation Groups](https://symfony.com/doc/current/validation/groups.html) for details.

```php 
use Symfony\Component\Validator\Constraints as Assert;
use gcgov\framework\models\customConstraints as CustomAssert;

#[Assert\NotBlank]
public ?\app\models\keyValueItem $projectType = null;

#[Assert\Expression( expression: 'this.externalApplicantRequests or value', message: 'At least one applicant is required' )]
/** @var \app\models\applicant[] $applicants */
public array $applicants = [];

```

### Validation Groups
Classes implementing validation groups must add method `public function _defineValidationGroups(): string[]`. Groups 
allow fields to be conditionally validated. 

Example: 
```php

final class project extends \gcgov\framework\services\mongodb\model {
    ...
    //required if any of the validation groups listed are present in _defineValidationGroups 	
    #[CustomAssert\OptionalValid(expression:'value!=null', groups:[ constants::EXTERNAL_PROJECT_TYPE_ID_NEW_HOME, constants::EXTERNAL_PROJECT_TYPE_ID_RES_ADDITION ])]
    public ?\app\models\component\externalContractorRequest $externalBuildingContractorRequest = null;

	public function _defineValidationGroups(): array {
		$validationGroups = [];
		if( $this->projectType instanceof keyValueItem ) {
			$validationGroups = [ (string)$this->projectType->_id ];
		}
		return $validationGroups;
	}
    ...
}
```

### Update Validation Status
To run validation, call the `updateValidationState` method on the model or embedded object.
```php
${modelOrEmbeddedInstance}->updateValidationState(); 
```
