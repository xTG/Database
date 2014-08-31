<?php

/**
 * This class use PDO interface for Mysql and can cache the datas using Mysqlnd_qc or a custom file cache.
 * @author  xTG / Baptiste ROUSSEL
 * @version  0.1
 * @changelog 0.1 Creation
 * 
 * The Database class is free software.
 * It is released under the terms of the following BSD License.
 *
 * Copyright (c) 2014, Baptiste ROUSSEL
 * All rights reserved.
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 *
 *    * Redistributions of source code must retain the above copyright notice,
 *      this list of conditions and the following disclaimer.
 *
 *    * Redistributions in binary form must reproduce the above copyright notice,
 *      this list of conditions and the following disclaimer in the documentation
 *      and/or other materials provided with the distribution.
 *
 *    * Neither the name of Baptiste ROUSSEL nor the names of its
 *      contributors may be used to endorse or promote products derived from this
 *      software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
class Database
{
	protected $server = 'localhost';
	protected $username = 'root';
	protected $password = '';
	protected $database = '';
	protected $options = array();
	protected $port = '3306';
	protected $charset = 'UTF8';
	protected $dsn = '';
	protected $cacheDir = 'cache';
	protected $cacheName = 'query';

	private $isConnectionAlive = false;
	private $isQueryExecuted = false;
	private $isMysqlnd_qc = false;
	private $isFileCache = false;

	private $dbh = null;
	private $query = array();
	private $queryParameters = array();
	private $res = null;
	private $isDirectQuery = false;
	private $cachedData = array();

	private $isPreparedStatment = false;

	/**
	 * [__construct constructor]
	 */
	public function __construct()
	{
		$this->isConnectionAlive = false;

		if( function_exists('mysqlnd_qc_clear_cache') )
		{
			$this->isMysqlnd_qc = true;
		}
		else if( file_exists($this->cacheDir) )
		{
			$this->isFileCache = true;
		}

		return $this;
	}

	/**
	 * [__call Catch PDO methods]
	 * @param  [string] $name [function name]
	 * @param  [array] $args [arguments]
	 */
	function __call($name, $args)
	{

		if( $this->res !== null && method_exists($this->res, $name) )
		{
			$return = call_user_func_array(array($this->res, $name), $args);
			return ( $return === null )? $this : $return;
		}

		if( $this->dbh === null ) /* If the connexion is not initialized we open it */
			$this->verifConnection();
		if( method_exists($this->dbh, $name) )
		{
			$return = call_user_func_array(array($this->dbh, $name), $args);
			return ( $return === null )? $this : $return;
		}

		trigger_error(get_class($this).'::'.htmlspecialchars($name).
					  ' is not accessible.', E_USER_ERROR);
		return $this;
	}

	/**
	 * [set Assign value to configuration variables.]
	 * @param [string] $key   [name of the configuration]
	 * @param [???] $value [value]
	 */
	public function set($key, $value)
	{
		try
		{
			$prop = new ReflectionProperty(get_class($this), $key);
			if( !$prop->isPrivate() )
			{
				$this->$key = $value;

				/* Specific key processing */
				if( $key == 'cacheDir' )
				{
					if( !file_exists($this->cacheDir) )
					{
						$this->isFileCache = false;
						trigger_error(get_class($this).'::'.htmlspecialchars($key).' : '.htmlspecialchars($value).
							          ' is not a valid directory.', E_USER_WARNING);
					}
				}

			}
			else
			{
				trigger_error(get_class($this).'::'.htmlspecialchars($key).' is not accessible.', E_USER_ERROR);
			}
		}
		catch(Exception $e)
		{
			trigger_error(get_class($this).'::'.htmlspecialchars($key).' doesn\'t exists.', E_USER_ERROR);
		}

		return $this;
	}

	/**
	 * [verifConnection If connection is not alive open it.]
	 */
	private function verifConnection()
	{
		if( !$this->isConnectionAlive )
		{
			if( empty($this->dsn) )
			{
				$this->dsn = 'mysql:dbname='.$this->database.';server='.$this->server.';port='.$this->port.';charset='.$this->charset;
			}
			$this->dbh = new PDO($this->dsn, $this->username, $this->password, $this->options);
		}
	}

	/**
	 * [query PDO::query]
	 * @param  [string] $statement [Statement]
	 */
	public function query($statement)
	{
		$args = func_get_args();

		/* DirectQuery verification */
		if( stripos($statement, 'select') === 0 )
			$this->isDirectQuery = false;
		else
			$this->isDirectQuery = true; /* INSERT, DELETE, UPDATE, ect => no fetch needed */

		/* Mysqlnd_qc verification */
		if( $this->isMysqlnd_qc )
			$args[0] = "/*" . MYSQLND_QC_ENABLE_SWITCH . "*/" . $statement;

		$this->query = $args;
		$this->isQueryExecuted = false;
		$this->isPreparedStatment = false;
		$this->res = null;

		/* if it's a direct query we directly execute it, otherwise it will wait a fetch */
		if( $this->isDirectQuery )
		{
			$this->verifConnection();
			$this->executeQuery();
		}

		return $this;
	}

	/**
	 * [prepare PDO::prepare]
	 * @param  [string] $statement [Statement]
	 */
	public function prepare($statement)
	{
		$args = func_get_args();

		/* DirectQuery verification */
		if( stripos($statement, 'select') === 0 )
			$this->isDirectQuery = false;
		else
			$this->isDirectQuery = true; /* INSERT, DELETE, UPDATE, ect => no fetch needed */

		/* Mysqlnd_qc verification */
		if( $this->isMysqlnd_qc )
			$args[0] = "/*" . MYSQLND_QC_ENABLE_SWITCH . "*/" . $statement;

		$this->query = $args;
		$this->isQueryExecuted = false;
		$this->isPreparedStatment = true;
		$this->res = null;

		return $this;
	}

	/**
	 * [execute PDO::execute]
	 * @param  [array] $queryParameters [parameters]
	 */
	public function execute($queryParameters)
	{
		$this->queryParameters = $queryParameters;
		$this->isQueryExecuted = false;
		$this->res = null;

		/* if it's a direct query we directly execute it, otherwise it will wait a fetch */
		if( $this->isDirectQuery )
		{
			$this->verifConnection();
			$this->executeQuery();
		}
	}

	/**
	 * [fetch Fetch next data]
	 * @return [array] [data]
	 */
	public function fetch()
	{
		$this->verifConnection();

		if( empty($this->cachedData) )
		{
			if( $this->isFileCache && file_exists($this->getCacheFileName()) )
			{
				/* We get the data from file cache. */
				$this->readFromCache();
			}
			else
			{
				/* We get the datas from database */
				$this->executeQuery();
				while($row = call_user_func_array(array($this->res, 'fetch'), func_get_args()))
					$this->cachedData[] = $row;
				if( $this->isFileCache ) /* We save the datas into file cache. */
					$this->writeToCache();
			}
		}

		$row = @current($this->cachedData);
		@next($this->cachedData);
		return $row;
	}

	/**
	 * [fetchAll FetchAll datas]
	 * @return [array] [datas]
	 */
	public function fetchAll()
	{
		$this->verifConnection();

		if( empty($this->cachedData) )
		{
			if( $this->isFileCache && file_exists($this->getCacheFileName()) )
			{
				/* We get the data from file cache. */
				$this->readFromCache();
			}
			else
			{
				/* We get the datas from database */
				$this->executeQuery();
				$this->cachedData = call_user_func_array(array($this->res, 'fetchAll'), func_get_args());
				if( $this->isFileCache ) /* We save the datas into file cache. */
					$this->writeToCache();
			}
		}

		return $this->cachedData;
	}

	/**
	 * [executeQuery Executes the query.]
	 */
	private function executeQuery()
	{
		if( $this->res === null )
		{
			if( $this->isPreparedStatment === false )
				$this->res = call_user_func_array(array($this->dbh, 'query'), $this->query);
			else
			{
				$this->res = call_user_func_array(array($this->dbh, 'prepare'), $this->query);
				$this->res->execute($this->queryParameters);
			}
			$this->isQueryExecuted = true;
		}
	}

	/**
	 * [writeToCache Write data to file cache.]
	 */
	private function writeToCache()
	{
		file_put_contents($this->getCacheFileName(), serialize($this->cachedData));
	}

	/**
	 * [readFromCache Load the cache from file.]
	 */
	private function readFromCache()
	{
		$this->cachedData = unserialize(file_get_contents($this->getCacheFileName()));
	}

	/**
	 * [getCacheFileName Returns the path to the cache file.]
	 * @return [string] [cache filename with path]
	 */
	private function getCacheFileName()
	{
		if( !empty($this->cacheDir) )
			return $this->cacheDir . '/' . $this->cacheName;
		else
			return $this->cacheName;
	}

	/**
	 * [cleanCache Delete the cache.]
	 */
	public function cleanCache()
	{
		if( file_exists($this->getCacheFileName()) )
			unlink($this->getCacheFileName());
		$this->cachedData = array();
	}
}

?>
