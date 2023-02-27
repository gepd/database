<?php

namespace Utopia\Tests\Adapter;

use PDO;
use Redis;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Tests\Base;

class MariaDBTest extends Base
{
    /**
     * @var Database
     */
    static $database = null;

    // TODO@kodumbeats hacky way to identify adapters for tests
    // Remove once all methods are implemented
    /**
     * Return name of adapter
     *
     * @return string
     */
    static function getAdapterName(): string
    {
        return "mariadb";
    }

    /**
     * @return Database
     */
    static function getDatabase(): Database
    {
        if(!is_null(self::$database)) {
            return self::$database;
        }

        $dbHost = 'mariadb';
        $dbPort = '3306';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPDOAttributes());
        $redis = new Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();
        $cache = new Cache(new RedisAdapter($redis));

        $database = new Database(new MariaDB($pdo), $cache);
        $database->setDefaultDatabase('utopiaTests');
        $database->setNamespace('myapp_'.uniqid());

        if (!$database->exists('utopiaTests')) {
            $database->create();
        }

        return self::$database = $database;
    }

    /**
     * @throws Limit
     * @throws Duplicate
     * @throws Throwable
     */
    public function testOneToOneOneWayRelationship(): void
    {
        static::getDatabase()->createCollection('person');
        static::getDatabase()->createCollection('library');

        static::getDatabase()->createRelationship(
            collection: 'person',
            relatedCollection: 'library',
            type: Database::RELATION_ONE_TO_ONE
        );

        // Check metadata for collection
        $collection = static::getDatabase()->getCollection('person');
        $attributes = $collection->getAttribute('attributes', []);

        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'library') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('library', $attribute['$id']);
                $this->assertEquals('library', $attribute['key']);
                $this->assertEquals('library', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_ONE_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(false, $attribute['options']['twoWay']);
                $this->assertEquals('person', $attribute['options']['twoWayId']);
            }
        }

        // Check metadata for related collection
        $collection = static::getDatabase()->getCollection('library');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'person') {
                $this->fail('Attribute should not be added to related collection');
            }
        }

        // Create document with relationship with nested data
        static::getDatabase()->createDocument('person', new Document([
            '$id' => 'person1',
            'library' => [
                '$id' => 'library1'
            ],
        ]));

        // Create document with relationship with related ID
        static::getDatabase()->createDocument('library', new Document([
            '$id' => 'library2',
        ]));
        static::getDatabase()->createDocument('person', new Document([
            '$id' => 'library2',
            'library' => 'library2'
        ]));

        // Get document with relationship
        $person = static::getDatabase()->getDocument('person', 'person1');
        $library = $person->getAttribute('library', []);
        $this->assertEquals('library1', $library['$id']);

        // Get related document
        $library = static::getDatabase()->getDocument('library', 'library1');
        $person = $library->getAttribute('person');
        $this->assertEquals(null, $person);
    }

    /**
     * @throws Authorization
     * @throws Duplicate
     * @throws Limit
     * @throws Throwable
     * @throws Structure
     */
    public function testOneToOneTwoWayRelationship(): void
    {
        static::getDatabase()->createCollection('country');
        static::getDatabase()->createCollection('city');

        static::getDatabase()->createRelationship(
            collection: 'country',
            relatedCollection: 'city',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true
        );

        $collection = static::getDatabase()->getCollection('country');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'city') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('city', $attribute['$id']);
                $this->assertEquals('city', $attribute['key']);
                $this->assertEquals('city', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_ONE_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(true, $attribute['options']['twoWay']);
                $this->assertEquals('country', $attribute['options']['twoWayId']);
            }
        }

        $collection = static::getDatabase()->getCollection('city');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'country') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('country', $attribute['$id']);
                $this->assertEquals('country', $attribute['key']);
                $this->assertEquals('country', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_ONE_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(true, $attribute['options']['twoWay']);
                $this->assertEquals('city', $attribute['options']['twoWayId']);
            }
        }

        // Create document with relationship with nested data
        static::getDatabase()->createDocument('country', new Document([
            '$id' => 'country1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'city' => [
                '$id' => 'city1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
            ],
        ]));

        // Create document with relationship with related ID
        static::getDatabase()->createDocument('city', new Document([
            '$id' => 'city2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]));
        static::getDatabase()->createDocument('country', new Document([
            '$id' => 'country2',
            'city' => 'city2'
        ]));

        // Get document with relationship
        $city = static::getDatabase()->getDocument('city', 'city1');
        $this->assertEquals('country1', $city['country']['$id']);

        // Get inverse document with relationship
        $country = static::getDatabase()->getDocument('country', 'country1');
        $this->assertEquals('city1', $country['city']['$id']);
    }
}