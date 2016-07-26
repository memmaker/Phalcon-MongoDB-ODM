<?php

namespace MemMaker\MongoDB;

use MongoDB\Driver\Command;
use MongoDB\Database;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;
use Phalcon\Di;
use Phalcon\Mvc\CollectionInterface;
use Phalcon\Text;

// autoloading would not work for me, TODO: make it work
require_once 'Exceptions/EntryNotFoundException.php';
require_once 'Exceptions/ErrorOnInsertException.php';

class Model extends \MongoDB\Collection
{

    protected $_id;
    protected $_attributes;

    public static function collection()
    {
        return (new static(Di::getDefault()->get('mongo'), Di::getDefault()->get('config')->mongodb->database, static::getSource()));
    }

    public static function mongoTime()
    {
        return new UTCDateTime(round(microtime(true) * 1000) . '');
    }

    public function getId($asString = true)
    {
        return $asString ? (string)$this->_id : $this->_id;
    }

    public static function destroyDatabase()
    {
        $dbname = Di::getDefault()->get('config')->mongodb->database;
        $manager = Di::getDefault()->get('mongo');
        $db = new Database($manager, $dbname);
        $db->drop();
    }

    public function unsetField($field)
    {
        $path = explode('.', $field);
        $lastPart = end($path);
        if (count($path) > 1) {
            $ref = $this->getAttrRef($field, 1);
        } else {
            $ref = &$this->_attributes;
        }
        if ($ref != false) {
            $type = gettype($ref);
            if ($type == 'object' && isset($ref->{$lastPart})) {
                unset($ref->{$lastPart});
            } else if ($type == 'array' && isset($ref[$lastPart])) {
                unset($ref[$lastPart]);
            } else {
                return false;
            }
            $this->updateOne(['_id' => $this->_id], ['$unset' => [$field => '']]);
            return true;
        }
        return false;
    }

    protected function castArrayAttributes(array $data, $useMutators = false)
    {
        foreach ($data as $param => $value)
        {
            if ($useMutators)
            {
                $methodName = 'set' . Text::camelize($param);
                $data[$param] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($param, $value);
            }
            else
            {
                $data[$param] = $this->castAttribute($param, $value);
            }
        }
        return $data;
    }

    public function castAttribute($param, $value)
    {
        if (isset(static::$casts[$param])) {
            $type = static::$casts[$param];
            if ($type == 'id') {
                if (!($value instanceof ObjectID)) {
                    try {
                        return new ObjectID((string)$value);
                    } catch (\Exception $e) {
                        return null;
                    }
                }
                return $value;
            } else if (in_array($type, ['integer', 'float', 'boolean', 'string', 'array', 'object'])) {
                settype($value, $type);
            }
        }
        return $value;
    }

    protected function event($name)
    {
        if (method_exists($this, $name)) {
            $this->{$name}();
        }
    }

    protected function getAttrRef($path, $rightOffset = 0)
    {
        $path = explode('.', $path);
        $length = count($path) - $rightOffset;
        $return = &$this->_attributes;
        for ($i = 0; $i <= $length - 1; ++$i) {
            if (isset($return->{$path[$i]})) {
                if ($i == $length - 1) {
                    return $return->{$path[$i]};
                } else {
                    $return = &$return->{$path[$i]};
                }
            } else if (isset($return[$path[$i]])) {
                if ($i == $length - 1) {
                    return $return[$path[$i]];
                } else {
                    $return = &$return[$path[$i]];
                }
            } else {
                return false;
            }
        }
        return $return;
    }

    public function __get($name)
    {
        $methodName = 'get' . Text::camelize($name);
        return isset($this->_attributes[$name]) ? (method_exists($this, $methodName) ? $this->{$methodName}($this->_attributes[$name]) : $this->_attributes[$name])
            : (isset($this->_relations[$name]) ? $this->_relations[$name]
                : (isset(static::$relations[$name]) ? $this->loadRelation($name) : null));
    }

    public function __set($name, $value)
    {
        $methodName = 'set' . Text::camelize($name);
        $this->_attributes[$name] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($name, $value);
    }

    public function __toString()
    {
        return json_encode($this->toArray());
    }

    public static function mapReduce($mapJS, $reduceJS, array $query = array())
    {
        $map = new Javascript($mapJS);
        $reduce = new Javascript($reduceJS);

        $source = static::getSource();
        $command = new Command([
            "mapreduce" => $source,
            "map" => $map,
            "reduce" => $reduce,
            "query" => new \stdClass(), //new BSONDocument($query),
            "out" => 'results'
        ]);

        $manager = Di::getDefault()->get('mongo');
        $results = $manager->executeCommand('TheBackend', $command);

        return $results;
    }


    public function beforeCreate()
    {
        //$this->_id = strval(new \MongoId());
        $this->timestamp_entry_created = new UTCDatetime(time()*1000);
        $this->created_at = self::mongoTime();

        $session = Di::getDefault()->getShared('session');
        $username = $session->get('username');

        $this->entry_created_by = $username;
    }

    public function beforeSave()
    {
        $this->timestamp_entry_last_modified = new UTCDatetime(time()*1000);

        $session = Di::getDefault()->getShared('session');
        $username = $session->get('username');

        $this->entry_last_modified_by = $username;

        $this->changedFields = array();
    }

    public function beforeUpdate()
    {

        $this->updated_at = self::mongoTime();
    }

    public static function getFullTextSearchQuery($model, $searchString, $searchLimit = 500)
    {
        $searchFields = array();
        foreach ($model['searchFields'] as $fieldname) {
            $searchFields[] = array($fieldname => new \MongoRegex('/' . $searchString . '/iu'));
        }
        return
            array(
                array('$or' => $searchFields),
                'sort' => $model['sortFieldOrder'],
                'limit' => $searchLimit
            );
    }

    /* Assigns all fields it can find in $data to the $this model object.
    /* Tries to parse everything correct according to fieldtype.  */
    public function assign($data)
    {
        $modelclassname = get_class($this);
        $model = $modelclassname::getModel();

        foreach ($model['fields'] as $fieldname => $fieldFlags)
        {
            if (!array_key_exists($fieldname, $data))
            {
                // no value for this fieldtype
                continue;
            }

            $fieldtype = $fieldFlags['type'];

            $this->$fieldname = array_key_exists('default', $fieldFlags) ? $fieldFlags['default'] : '';

            $valueConverter = Di::getDefault()->getShared('valueConverter');

            $convertedValue = $valueConverter->getConversion($data[$fieldname], $fieldtype);

            $this->setField($fieldname, $convertedValue);
        }
    }

    public function setField($fieldname, $value)
    {
        if ($this->$fieldname !== $value && (! in_array($fieldname, $this->changedFields)))
        {
            // only add to changedFields if the value changed and the field is not already in there
            $this->changedFields[] = $fieldname;
            $this->$fieldname = $value;
        }
    }


    public function increment($argument, $value = 1)
    {
        $this->{$argument} += $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);
        return $this;
    }

    public function decrement($argument, $value = 1)
    {
        $this->{$argument} -= $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);
        return $this;
    }

    public function save()
    {
        if ($attributes != null) {
            $this->fill($attributes);
        }
        $this->event('beforeSave');
        if (isset($this->_id)) {
            $this->event('beforeUpdate');
            $this->updateOne(['_id' => $this->_id], ['$set' => $this->_attributes]);
            $this->event('afterUpdate');
        } else {
            $this->event('beforeCreate');
            $insertResult = $this->insertOne($this->_attributes);
            $this->_id = $insertResult->getInsertedId();
            $this->event('afterCreate');
        }
        $this->event('afterSave');
        return $this;
    }

    public static function get(array $filter = [], array $options = [])
    {
        return static::collection()->find($filter, $options);
    }

    protected static function getMatchPipeline($query)
    {
        return ['$match' => $query];
    }

    protected static function getLookUpPipeline($localFieldname, $refCollectionName, $asLocalFieldname)
    {
        return ['$lookup' => [
            'from' => $refCollectionName,
            'localField' => $localFieldname,
            'foreignField' => "_id",
            'as' => $asLocalFieldname
        ]];
    }

    public function insert($entry)
    {
        return static::collection()->insertOne($entry);
    }

    public function delete()
    {
        $this->event('beforeDelete');
        $this->deleteOne(['_id' => $this->getId(false)]);
        $this->event('afterDelete');
        return $this;
    }


    public function update(array $attributes)
    {
        $this->event('beforeSave');
        $this->event('beforeUpdate');
        $this->fill($attributes);
        static::collection()->updateOne(['_id' => $this->_id], ['$set' => $attributes]);
        $this->event('afterUpdate');
        $this->event('afterSave');
        return $this;
    }

    public static function replaceById($id, array $data)
    {
        $result = static::collection()->replaceOne(['_id' => $id], $data);
        return $result;
    }

    public static function create(array $data)
    {
        $data['_id'] = (string) (new ObjectID());
        $result = static::collection()->insertOne($data);
        return $result;
    }

    public static function getById($id)
    {
        $collection = static::collection();
        $result = $collection->findOne(['_id' => $id]);
        if ($result == null)
        {
            throw new \MemMaker\MongoDB\Exceptions\EntryNotFoundException(vsprintf("Entry with id '%1\$s' not found in collection '%2\$s'", [$id, $collection->getCollectionName()]));
        }
        return $result;
    }

    public static function deleteById($id)
    {
        return static::collection()->deleteOne(['_id' => $id]);
    }


}
