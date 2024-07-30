# MongoDB

`\gcgov\framework\services\mongodb`

## Usage

You will primarily interact with this service through classes that you extend to define data structures.

### Models

#### Definition
A class that extends `\gcgov\framework\services\mongodb\model` is a representation of the documents in a Mongo
collection. 

Example: This `inspection` model defines the fields and types that documents stored in the `inspection` collection in 
Mongo will have.

```php 
final class inspection extends \gcgov\framework\services\mongodb\model {

	const _COLLECTION = 'inspection';
	const _HUMAN = 'inspection';
	const _HUMAN_PLURAL = 'inspections';

	#[label( 'Id' )]
	public \MongoDB\BSON\ObjectId $_id;

	#[label( 'Inspection Number' )]
	#[autoIncrement]
	public int $inspectionNumber = 0;
}
```
In Mongo, the documents in the `inspection` collection will look like:
```json
{
  "_id": ObjectId(),
  "inspectionNumber": 0
}
```

#### Factory Methods
A model that extends `\gcgov\framework\services\mongodb\model` will gain static methods that can be used for storing and retrieving documents from Mongo.
```php
\app\models\inspection::aggregation( array $pipeline = [], $options = [] )
\app\models\inspection::getPagedResponse( int|string|null $limit, int|string|null $page, array $filter = [], array $options = [] )
\app\models\inspection::getAll( array $filter = [], array $sort = [], array $options = [] )
\app\models\inspection::getOne( \MongoDB\BSON\ObjectId|string $_id )
\app\models\inspection::getOneBy( array $filter = [], array $options = [] )
\app\models\inspection::saveMany( array &$objects, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::save( object &$object, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::delete( \MongoDB\BSON\ObjectId|string $_id, ?\MongoDB\Driver\Session $mongoDbSession = null )
\app\models\inspection::deleteMany( array $itemsToDelete, ?\MongoDB\Driver\Session $mongoDbSession = null )
```

## Attributes
```php 
#[label( 'Inspection Number' )]
#[autoIncrement]
```
