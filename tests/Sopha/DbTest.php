<?php

/**
 * Sopha - A PHP 5.x Interface to CouchDB
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://prematureoptimization.org/sopha/license/new-bsd
 * 
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @package    Sopha
 * @category   Tests
 * @version    $Id$
 * @license    http://prematureoptimization.org/sopha/license/new-bsd 
 */

// Load the test helper
require_once dirname(__FILE__) . '/../TestHelper.php';

require_once 'Sopha/Db.php';

/**
 * Sopha_Db test case
 * 
 */
class Sopha_DbTest extends PHPUnit_Framework_TestCase
{
    /**
     * Database URL to test against (taken from TestConfiguration.php)
     *
     * @var string
     */
    protected $_url;

    /**
     * Set up the test environment before every test
     *
     */
    protected function setUp()
    {
        parent::setUp();
        
        if (defined('SOPHA_TEST_DB_URL') && SOPHA_TEST_DB_URL) {
            $this->_url = SOPHA_TEST_DB_URL;
        } else {
            $this->_url = null;
        }
    }

    /*************************************************************************
     * Constructor and static tests
     *************************************************************************/
    
    /**
     * Make sure the constructor properly creates the DB URL. 
     * 
     * Also tests getUrl()
     *
     */
    public function testConstructorCreateUrl()
    {
        $urls = array(
            'http://localhost:5984/mydb/' =>
                new Sopha_Db('mydb'),
            'http://couchserver:5984/mydb/' =>
                new Sopha_Db('mydb', 'couchserver'),
            'http://couchserver:591/mydb/' => 
                new Sopha_Db('mydb', 'couchserver', 591),
            'http://couch.example.net:13/fu%2Fgu/' => 
                new Sopha_Db('fu/gu', 'couch.example.net', 13),
            'http://1.2.3.4:10001/db_$()%2F+-x/' =>
                new Sopha_Db('db_$()/+-x', '1.2.3.4', 10001)
        );
        
        foreach ($urls as $expected => $db) {
            $url = $db->getUrl();
            $this->assertEquals($expected, $url);
        }
    }
    
    /**
     * Make sure the constructor fails with an exception with invalid db names
     * 
     */
    public function testConstructorInvalidDbNames()
    {
        $tests = array(
            '0digit',
            'UpperCase',
            'moreUpperCase',
            'in!valid',
            'has space',
            '_underscore',
            'in:valid',
            'in?valid'
        );
        
        foreach ($tests as $t) {
            try {
                $db = new Sopha_Db($t);
                $this->fail("Invalid db name '$t' provided, but no exception thrown");
                
            } catch (Sopha_Exception $e) {
                // All is good
            }
        }
    }
    
    /**
     * Make sure the constructor fails if an invalid hostname is provided
     */
    public function testConstructorInvalidHostNames()
    {
        $tests = array(
            '',
            'local host',
            'local_host',
            'foo%bar',
            'baz@baz.com',
            'שטוייעס.com',
            '--dot.com',
            'local/host',
            'http://'
        );
        
        foreach ($tests as $t) {
            try {
                $db = new Sopha_Db('mydb', $t);
                $this->fail("Invalid hostname '$t' provided, but no exception thrown");
                
            } catch (Sopha_Exception $e) {
                // All is good
            }
        }
        
    }
    
    /**
     * Make sure the constructor fails if an invalid port is provided
     *
     */
    public function testConstructorInvalidPorts()
    {
        $tests = array(
            0,
            -12,
            0x10000,
            ':55',
            'string',
        );
        
        foreach ($tests as $t) {
            try {
                $db = new Sopha_Db('mydb', 'localhost', $t);
                $this->fail("Invalid port '$t' provided, but no exception thrown");
                
            } catch (Sopha_Exception $e) {
                // All is good
            }
        }
    }
    
    
    
    /**
     * Test that we can properly create a DB
     *
     */
    public function testCreateDb()
    {
        if (! $this->_url) $this->markTestSkipped("Test requires a CouchDb server set up - see TestConfiguration.php");
        
        list($host, $port, $dbname) = $this->_getUrlParts();
        $dbname = trim($dbname, '/');
        
        $db = Sopha_Db::createDb($dbname, $host, $port);
        
        // Make sure DB now exists
        $response = Sopha_Http_Request::get($this->_url);
        $this->assertEquals(200, $response->getStatus());
    }
    
    /**
     * Make sure we get an exception when trying to create an existing DB 
     *
     */
    public function testCreateExistingDbFails()
    {
        if (! $this->_url) $this->markTestSkipped("Test requires a CouchDb server set up - see TestConfiguration.php");
        
        list($host, $port, $dbname) = $this->_getUrlParts();
        $dbname = trim($dbname, '/');
        
        try {
            $db = Sopha_Db::createDb($dbname, $host, $port);
            $this->fail("::createDb was expected to fail with a 409 error code");
        } catch (Sopha_Db_Exception $e) {
            $this->assertEquals(409, $e->getCode(), "Error code is not 409");
        }
    }
    
    /**
     * Test that we can properly delete a 
     */
    public function testDeleteDb()
    {
        if (! $this->_url) $this->markTestSkipped("Test requires a CouchDb server set up - see TestConfiguration.php");
        
        list($host, $port, $dbname) = $this->_getUrlParts();
        $dbname = trim($dbname, '/');
        
        $res = Sopha_Db::deleteDb($dbname, $host, $port);
        $this->assertTrue($res);
        
        // Make sure the DB no longer exists
        $response = Sopha_Http_Request::get($this->_url);
        $this->assertEquals(404, $response->getStatus());
    }

    /**
     * Test that we can get a list of all DBs from the server
     */
    public function testGetAllDbs()
    {
        if (! $this->_url) $this->markTestSkipped("Test requires a CouchDb server set up - see TestConfiguration.php");
        
        list($host, $port, $dbname) = $this->_getUrlParts();
        $dbname = trim($dbname, '/');
        
        // First, create a DB
        Sopha_Db::createDb($dbname, $host, $port);
        
        // Make sure DB exists by looking for it in the list of all DBs
        $dbs = Sopha_Db::getAllDbs($host, $port);
        $this->assertType('array', $dbs);
        $this->assertTrue(in_array($dbname, $dbs));
        
        // Delete the DB and make sure it is no longer in the list
        Sopha_Db::deleteDb($dbname, $host, $port);
        $dbs = Sopha_Db::getAllDbs($host, $port);
        $this->assertType('array', $dbs);
        $this->assertFalse(in_array($dbname, $dbs));
    }

    /*************************************************************************
     * Dynamic (object) tests
     *************************************************************************/
    
    /**
     * Tests Sopha_Db->create()
     */
    public function testCreate()
    {
        // TODO Auto-generated Sopha_DbTest->testCreate()
        $this->markTestIncomplete("create test not implemented");
        $this->Sopha_Db->create(/* parameters */);
    }
    
    /**
     * Tests Sopha_Db->delete()
     */
    public function testDelete()
    {
        // TODO Auto-generated Sopha_DbTest->testDelete()
        $this->markTestIncomplete("delete test not implemented");
        $this->Sopha_Db->delete(/* parameters */);
    }
    
    /**
     * Tests Sopha_Db->getInfo()
     */
    public function testGetInfo()
    {
        // TODO Auto-generated Sopha_DbTest->testGetInfo()
        $this->markTestIncomplete("getInfo test not implemented");
        $this->Sopha_Db->getInfo(/* parameters */);
    }

    /**
     * Tests Sopha_Db->retrieve()
     */
    public function testRetrieve()
    {
        // TODO Auto-generated Sopha_DbTest->testRetrieve()
        $this->markTestIncomplete("retrieve test not implemented");
        $this->Sopha_Db->retrieve(/* parameters */);
    }

    /**
     * Tests Sopha_Db->update()
     */
    public function testUpdate()
    {
        // TODO Auto-generated Sopha_DbTest->testUpdate()
        $this->markTestIncomplete("update test not implemented");
        $this->Sopha_Db->update(/* parameters */);
    }

    /**
     * Tests Sopha_Db->view()
     */
    public function testView()
    {
        // TODO Auto-generated Sopha_DbTest->testView()
        $this->markTestIncomplete("view test not implemented");
        $this->Sopha_Db->view(/* parameters */);
    }
    
    /**
     * Get the host, port and path of a provided URL
     *
     * @param  string $url
     * @return array
     */
    protected function _getUrlParts($url = null)
    {
        if (! $url) $url = $this->_url;
        $parts = parse_url($url);
        
        return array($parts['host'], $parts['port'], $parts['path']);
    }
}
