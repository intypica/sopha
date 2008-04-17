<?php

/**
 * Sopha - A PHP 5.x Interface to CouchDB 
 * 
 * @package    Sopha
 * @subpackage Db
 * @author     Shahar Evron
 * @version    $Id$
 * @license    LICENSE.txt - New BSD License 
 */

require_once 'Sopha/Http/Request.php';
require_once 'Sopha/Document.php';

class Sopha_Db
{
    const COUCH_PORT = 5984;
     
    protected $_db_uri;
    
    /**
     * Create a new DB connector
     *
     * @param string  $dbname Database name
     * @param string  $host   Database host - defaults to localhost
     * @param integer $port   Database port - defaults to 5984
     */
    public function __construct($dbname, $host = 'localhost', $port = self::COUCH_PORT)
    {
        $this->_db_uri = self::makeDbUrl($dbname, $host, $port);
    }
    
    /**
     * Get info about the current database
     *
     * @return unknown
     */
    public function getInfo()
    {
        $response = Sopha_Http_Request::get($this->_db_uri);
        
        if (! $response->isSuccess()) {
            require_once 'Sopha/Db/Exception.php';
            switch($response->getStatus()) {
                case 404:
                    throw new Sopha_Db_Exception("Database does not exist");
                    break;
                    
                default:
                    throw new Sopha_Db_Exception("Unexpected response from server: " . 
                        "{$response->getStatus()} {$response->getMessage()}", $response>getStatus());
                    break;
            }
        }
        
        return $response->getDocument();
    }
    
    /**
     * Get all documents from DB
     *
     * @param  string  $startKey   Key to start from
     * @param  integer $limit      Limit result set size
     * @param  boolean $descending Descending order
     * @return array
     */
    public function getAllDocs($startKey = null, $limit = null, $descending = false)
    {
        $request = new Sopha_Http_Request($this->_db_uri . '_all_docs');
        
        if ($startKey !== null) $request->addQueryParam('startkey', $startKey);
        if ($limit !== null) $request->addQueryParam('limit', $limit);
        if ($descending) $request->addQueryParam('descending', 'true');
        
        $response = $request->send();

        if (! $response->isSuccess()) {
            require_once 'Sopha/Db/Exception.php';
            switch($response->getStatus()) {
                case 404:
                    throw new Sopha_Db_Exception("Database does not exist");
                    break;
                    
                default:
                    throw new Sopha_Db_Exception("Unexpected response from server: " . 
                        "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                    break;
            }
        }
        
        $doc = $response->getDocument();
        return $doc['rows'];
    }

    /**
     * Document CRUD methods
     */
    
    /**
     * Retrieve a document from the DB
     *
     * @param  string  $doc
     * @param  string  $class Class to return - must extend Sopha_Document
     * @param  string  $rev
     * @param  boolean $full
     * @return Sopha_Document|boolean
     */
    public function retrieve($doc, $class = 'Sopha_Document', $rev = null, $full = false)
    {
        $url = $this->_db_uri . urlencode($doc);
        $request = new Sopha_Http_Request($url);
        if ($rev !== null) $request->addQueryParam('rev', $rev);
        if ($full) $request->addQueryParam('full', 'true'); 
        
        $response = $request->send();
        
        switch($response->getStatus()) {
            case 200:
                $obj = new $class($response->getDocument(), $url, $this);
                if (! $obj instanceof Sopha_Document) {
                    require_once 'Sopha/Db/Exception.php';
                    throw new Sopha_Db_Exception("Class $class is expected to extend Sopha_Document");
                }
                return $obj;
                break;
                
            case 404:
                return false;
                break;
                
            default:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: " . 
                	"{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }
    
    /**
     * Create a new document and save it in DB. Will return the new Document object
     *
     * @param  mixed  $data
     * @param  string $doc
     * @return Sopha_Document
     */
    public function create($data, $doc = null)
    {
        require_once 'Zend/Json.php';
        
        $url = $this->_db_uri;
        
        if ($doc) {
            $response = Sopha_Http_Request::put($url . urlencode($doc), Zend_Json::encode($data));
        } else {
            $response = Sopha_Http_Request::post($url, Zend_Json::encode($data));
        }
        
        switch ($response->getStatus()) {
            case 201:
                $responseData = $response->getDocument();
                $url .= urlencode($responseData['id']);
                
                $data['_id'] = $responseData['id'];
                $data['_rev'] = $responseData['rev'];

                require_once 'Sopha/Document.php';                                                 
                return new Sopha_Document($data, $url, $this);
                break;
                
            default:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: " . 
                	"{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }
    
    /**
     * Update an existing document. 
     * 
     * The document must have a _id field, or the url has to be specified in 
     * $doc. Also, the document must have a _rev field. Will return the doc's
     * new revision.
     *
     * @param  mixed   $data
     * @param  string  $url
     * @return string  New revision  
     */
    public function update($data, $url = null)
    {
        require_once 'Zend/Json.php';
        
        // Convert object to array if needed, and get revision and URL
        if ($data instanceof Sopha_Document) {
            if (! $url) $url = $data->getUrl();
            $data = $data->toArray(true);
            
        } elseif (is_array($data)) {
            if (! $url && isset($data['_id'])) $url = $this->_db_uri . $data['_id'];
            
        } else {
            require_once 'Sopha/Db/Exception.php';
            throw new Sopha_Db_Exception("Data is expected to be either an array or a Sopha_Document object");
        }
        
        // Make sure we have a URL and a revision
        if (! $url) {
            require_once 'Sopha/Db/Exception.php';
            throw new Sopha_Db_Exception("Unable to update a document without a known URL");
        }
        
        if (! isset($data['_rev'])) {
            require_once 'Sopha/Db/Exception.php';
            throw new Sopha_Db_Exception("Unable to update a document without a known revision");
        }
        
        $response = Sopha_Http_Request::put($url, Zend_Json::encode($data));
        
        switch ($response->getStatus()) {
            case 201:
                $responseData = $response->getDocument();
                return $responseData['rev'];
                break;
                
            case 409:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Cannot save updated document: revision conflict", 409);
                break;
                
            default:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: " . 
                	"{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }
    
    /**
     * Delete a doc from DB
     *
     * @param  string $doc Document ID
     * @param  string $rev Revision
     * @return boolean 
     */
    public function delete($doc, $rev)
    {
        $url = $this->_db_uri . urlencode($doc);
        $request = new Sopha_Http_Request($url, 'DELETE');
        $request->addQueryParam('rev', $rev);
        
        $response = $request->send();
        
        switch ($response->getStatus()) {
            case 202:
                return true;
                break;
                
            case 404:
                return false;
                break;
                
            default:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: " . 
                	"{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }
    
    /**
     * Call a view document
     * 
     * @param  string $view   View to call
     * @param  array  $params Parameters to pass to the view
     * @return mixed
     */
    public function view($view, array $params = array(), $return_doc = null)
    {
        require_once 'Zend/Json.php';
        
        $url = $this->_db_uri . '_view/' . $view;
        $request = new Sopha_Http_Request($url);
        
        foreach($params as $k => $v) {
            $request->addQueryParam($k, Zend_Json::encode($v));
        }
        
        $response = $request->send();
        
        switch($response->getStatus()) {
            case 200:
                require_once 'Sopha/View/Result.php';
                
                if (! $return_doc) $return_doc = Sopha_View_Result::RETURN_ARRAY;
                return new Sopha_View_Result($response->getDocument(), $return_doc);
                break;
                
            case 500:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("View document '$view' does not exist", $response->getStatus());
                break;
                
            default:
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: " . 
                	"{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }
    
    /**
     * Get the URL for this DB
     * 
     * @return string
     */
    public function getUrl()
    {
        return $this->_db_uri;
    }
    
    /**
     * Static DB manipulation functions
     */
    
    /**
     * Create a database on a host and return it as an object
     *
     * @param  string  $dbname
     * @param  string  $host
     * @param  integer $port
     * @return Sopha_Db
     */
    static public function createDb($dbname, $host = 'localhost', $port = self::COUCH_PORT)
    {
        $uri = self::makeDbUrl($dbname, $host, $port);
        $response = Sopha_Http_Request::put($uri);        
        
        switch($response->getStatus()) {
            case 201: // Expected
                return new Sopha_Db($dbname, $host, $port);
                break;
                
            case 409: // DB already exists
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Database '$dbname' already exists", 409);
                break;
                
            default: // Unexpected
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: {$response->getStatus()}", 
                    $response->getStatus());
                break;
        }
    }
    
    /**
     * Delete a database from a host
     *
     * @param  string  $dbname
     * @param  string  $host
     * @param  integer $port
     * @return boolean True if successful
     */
    static public function deleteDb($dbname, $host = 'localhost', $port = self::COUCH_PORT)
    {
        $url = self::makeDbUrl($dbname, $host, $port);
        $response = Sopha_Http_Request::delete($url);
        
        switch($response->getStatus()) {
            case 202: // Expected
                return true;
                break;
                
            case 404: // DB does not exists
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Database '$dbname' does not exist", 404);
                break;
                
            default: // Unexpected
                require_once 'Sopha/Db/Exception.php';
                throw new Sopha_Db_Exception("Unexpected response from server: {$response->getStatus()}", 
                    $response->getStatus());
                break;
        }
    }
    
    /**
     * Get list of all databases on a host
     *
     * @param  string  $host
     * @param  integer $port
     * @return array
     */
    static public function getAllDbs($host = 'localhost', $port = self::COUCH_PORT)
    {
        $url = substr(self::makeDbUrl('_all_dbs', $host, $port), 0, -1);
        
        $response = Sopha_Http_Request::get($url);
        if (! $response->isSuccess()) {
            require_once 'Sopha/Db/Exception.php';
            throw new Sopha_Db_Exception("Unexpected response from server: {$response->getStatus()}",
                $response->getStatus());
        }
        
        return $response->getDocument();
    }
    
    /**
     * Validate parts and create a database URL
     *
     * @param  string  $dbname
     * @param  string  $host
     * @param  integer $port
     * @return string
     */
    static protected function makeDbUrl($dbname, $host, $port)
    {
        // Validate dbname
        if (! preg_match('|^[a-z0-9_\$\(\)+\-/]+$|', $dbname)) {
            require_once 'Sopha/Exception.php';
            throw new Sopha_Exception("Invalid db name: '$dbname'");
        }
        
        // Validate host
        if (! preg_match('/^(?:(?:[a-zA-Z0-9\-]{1,63}\.)*[a-zA-Z0-9\-]{1,63}){1,254}$/', $host)) {
            require_once 'Sopha/Exception.php';
            throw new Sopha_Exception("Invalid host name: '$host'");
        }
        
        // Validate port
        $port = (integer) $port;
        if (! $port) {
            $port = self::COUCH_PORT;
            
        } elseif ($port < 0x1 || $port > 0xffff) {
            require_once 'Sopha/Exception.php';
            throw new Sopha_Exception("Invalid db port: '$port'");
            
        }
        
        return 'http://' . $host . ':' . $port . '/' . 
            str_replace('/', '%2F', $dbname) . '/'; 
    }
}