<?php
/**
 * OpenTraits\Crud\Mysql
 * 
 * Provides a simple model with basic database operations.
 * Example:
 * 
 * class Items {
 *  use OpenTraits\Crud\Mysql;
 * 
 *  static $table = 'items';
 *  static $fields = null;
 * }
 * 
 * Items::setConnection($Pdo);
 * 
 * $Item = Items::create(array(
 * 	'name' => 'Item name',
 * 	'description' => 'Item description'
 * ));
 * 
 * $Item->save();
 * $Item->name = 'New name for the item';
 * $Item->save();
 */
namespace OpenTraits\Crud;

trait Mysql {
	public static $connection;
	public static $debug = false;


	/**
	 * Set the database connection.
	 * 
	 * @param PDO $Db The database object
	 */
	public static function setConnection (\PDO $Connection) {
		static::$connection = $Connection;
	}


	/**
	 * Returns the names of the fields in the database
	 * 
	 * @return array The fields name
	 */
	public static function getFields () {
		if (empty(static::$fields)) {
			$table = static::$table;

			static::$fields = static::$connection->query("DESCRIBE `$table`", \PDO::FETCH_COLUMN, 0)->fetchAll();
		}

		return static::$fields;
	}


	/**
	 * returns the fields ready to use in a mysql query
	 * This function is useful to "import" a model inside another, you just have to include the fields names of the model.
	 * 
	 * Example:
	 * $fieldsQuery = User::getQueryFields();
	 * $posts = Post::fetchAll("SELECT posts.*, $fieldsQuery FROM posts, users WHERE posts.author = users.id");
	 * $posts[0]->User //The user model inside the post
	 * 
	 * @param string $name The name of the parameter used to the sub-model. If it's not defined, uses the model class name (without the namespace)
	 * 
	 * @return string The portion of mysql code with the fields names
	 */
	public static function getQueryFields ($name = null) {
		$table = static::$table;
		$fields = array();
		$class = get_called_class();

		if ($name === null) {
			$name = (($pos = strrpos($class, '\\')) === false) ? $class : substr($class, $pos + 1);
			$name = lcfirst($name);
		}

		foreach (static::getFields() as $field) {
			$fields[] = "`$table`.`$field` as `$class::$field::$name`";
		}

		return implode(', ', $fields);
	}


	/**
	 * Constructor
	 */
	public function __construct () {
		$this->init();
	}


	/**
	 * Initialize the values, resolve fields.
	 */
	public function init () {
		foreach (static::getFields() as $field) {
			if (!isset($this->$field)) {
				$this->$field = null;
			}
		}

		$fields = array();

		foreach ($this as $key => $value) {
			if (strpos($key, '::') !== false) {
				list($class, $field, $name) = explode('::', $key, 3);

				if (!isset($this->$name)) {
					$fields[] = $name;
					$this->$name = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
				}

				$this->$name->$field = $value;
				unset($this->$key);
			}
		}

		foreach ($fields as $name) {
			$this->$name->__construct();
		}
	}

	/**
	 * Execute a query and returns the statement object with the result
	 * 
	 * @param  string $query The Mysql query to execute
	 * @param  array $marks The marks passed to the statement
	 *
	 * @throws Exception On error preparing or executing the statement
	 * 
	 * @return PDOStatement The result
	 */
	public static function execute ($query, array $marks = null) {
		$statement = static::$connection->prepare($query);

		if ($statement === false) {
			throw new \Exception('MySQL error: '.implode(' / ', static::$connection->errorInfo()));
			return false;
		}

		if ($statement->execute($marks) === false) {
			throw new \Exception('MySQL statement error: '.implode(' / ', $statement->errorInfo()));
			return false;
		}

		if (is_array(static::$debug)) {
			static::debug($statement, $marks);
		}

		return $statement;
	}


	/**
	 * Save the current statement for debuggin purposes
	 * 
	 * @param  PDOStatement $statement The query statement
	 * @param  array $marks The marks passed to the statement
	 */
	public static function debug ($statement, array $marks = null) {
		static::$debug[] = [
			'statement' => $statement,
			'marks' => $marks,
			'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)
		];
	}



	/**
	 * Fetch all results of a mysql selection
	 * 
	 * @param string/PDOStatement $query The query for the selection
	 * @param array $marks Optional marks used in the query
	 * 
	 * @return array The result of the query or false if there was an error
	 */
	public static function fetchAll ($query, array $marks = null) {
		if (!($query instanceof \PDOStatement)) {
			$query = static::execute($query, $marks);
		}

		$result = $query->fetchAll(\PDO::FETCH_CLASS, get_called_class());

		return new Results($result);
	}


	/**
	 * Fetch the first result of a mysql selection
	 * 
	 * @param string/PDOStatement $query The query for the selection. Note that "LIMIT 1" will be automatically added
	 * @param array $marks Optional marks used in the query
	 * 
	 * @return object The result of the query or false if there was an error
	 */
	public static function fetch ($query, array $marks = null) {
		if (!($query instanceof \PDOStatement)) {
			$query = static::execute($query, $marks);
		}

		$query->setFetchMode(\PDO::FETCH_CLASS, get_called_class());

		return $query->fetch();
	}


	/**
	 * Shortcut to select a row by id
	 * 
	 * Example:
	 * $Item = Item::selectById(45);
	 * 
	 * @param int/array $id The id value
	 * @param string $name The name of the id field. By default "id"
	 * 
	 * @return object The result of the query or false if there was an error
	 */
	public static function selectById ($id, $name = 'id') {
		if (empty($id)) {
			return false;
		}

		$table = static::$table;

		if (is_array($id)) {
			$limit = count($id);
			$in = substr(str_repeat(', ?', $limit), 2);

			return static::fetchAll("SELECT * FROM `$table` WHERE $name IN ($in) LIMIT $limit", $id);
		}

		return static::fetch("SELECT * FROM `$table` WHERE $name = :id LIMIT 1", [':id' => $id]);
	}


	/**
	 * Select a row using custom conditions
	 * 
	 * Example:
	 * $Item = Item::selectOne('title = :title', [':title' => 'Titulo']);
	 * 
	 * @param string $where The "where" syntax.
	 * @param array $marks Optional marks used in the query
	 * 
	 * @return object The result of the query or false if there was an error
	 */
	public static function selectOne ($where, $marks = null) {
		$table = static::$table;

		return static::fetch("SELECT * FROM `$table` WHERE $where LIMIT 1", $marks);
	}


	/**
	 * Creates a empty object or, optionally, fill with some data
	 * 
	 * @param array $data Data to fill the option.
	 * 
	 * @return object The instantiated objec
	 */
	public static function create (array $data = null) {
		$Item = new static();

		if ($data !== null) {
			$Item->edit($data);
		}

		return $Item;
	}


	/**
	 * Edit the data of the object using an array (but doesn't save it into the database)
	 * It's the same than edit the properties of the object but check if the property name is in the fields list
	 * 
	 * @param array $data The new data
	 */
	public function edit (array $data) {
		$fields = static::getFields();

		foreach ($data as $field => $value) {
			if (!in_array($field, $fields)) {
				throw new \Exception("The field '$field' does not exists");
			}

			$this->$field = $value;
		}
	}


	/**
	 * Deletes the properties of the model (but not in the database)
	 */
	public function clean () {
		foreach (static::getFields() as $field) {
			$this->$field = null;
		}
	}


	/**
	 * Saves the object data into the database. If the object has the property "id", makes an UPDATE, otherwise makes an INSERT
	 * 
	 * @return boolean True if the row has been saved, false if doesn't
	 */
	public function save () {
		if (($data = $this->prepareToSave($this->getData())) === false) {
			return false;
		}

		unset($data['id']);

		foreach ($data as $field => $value) {
			if ($value === null) {
				unset($data[$field]);
			}
		}

		$table = static::$table;

		//Insert
		if (empty($this->id)) {
			$fields = implode(', ', array_keys($data));
			$marks = implode(', ', array_fill(0, count($data), '?'));

			if (static::execute("INSERT INTO `$table` ($fields) VALUES ($marks)", array_values($data))) {
				$this->id = static::$connection->lastInsertId();

				return true;
			}

			return false;
		}

		//Update
		$set = array();
		$id = intval($this->id);

		foreach ($data as $field => $value) {
			$set[] = "`$field` = ?";
		}

		$set = implode(', ', $set);

		return static::execute("UPDATE `$table` SET $set WHERE id = $id LIMIT 1", array_values($data)) ? true : false;
	}


	/**
	 * Deletes the current row in the database (but keep the data in the object)
	 * 
	 * @return boolean True if the register is deleted, false if any error happened
	 */
	public function delete () {
		if (!empty($this->id)) {
			$table = static::$table;
			$id = intval($this->id);

			if (static::execute("DELETE FROM `$table` WHERE id = $id LIMIT 1") !== false) {
				$this->id = null;

				return true;
			}
		}

		return false;
	}


	/**
	 * Prepare the data before to save. Useful to validate or transform data before save in database
	 * This function is provided to be overwrited by the class that uses this trait
	 * 
	 * @param array $data The data to save.
	 * 
	 * @return array The transformed data. If returns false, the data will be not saved.
	 */
	public function prepareToSave (array $data) {
		return $data;
	}


	/**
	 * Returns the fields data of the row
	 * 
	 * @return array The data of the row
	 */
	public function getData () {
		$data = array();

		foreach (static::getFields() as $field) {
			$data[$field] = isset($this->$field) ? $this->$field : null;
		}

		return $data;
	}
}
?>