<?php
namespace CsrDelft\Orm\Entity;

use CsrDelft\Orm\Persistence\DatabaseAdmin;
use CsrDelft\Orm\Util;
use Exception;

/**
 * PersistentEntity.php
 *
 * @author P.W.G. Brussee <brussee@live.nl>
 *
 * Requires static properties in subclass: $persistent_attributes, $primary_key and $table_name
 *
 * @see PersistenceModel->retrieveAttributes for a usage example of sparse and foreign keys.
 *
 * Optional: static $rename_attributes = array('old_name' => 'new_name');
 */
abstract class PersistentEntity implements Sparse, \JsonSerializable {

	/**
	 * Static constructor is called (by inheritance) once and only from PersistenceModel.
	 *
	 * Optional: run conversion code before checkTables() here
	 */
	public static function __static() {
		// Extend the persistent attributes with all parent persistent attributes
		$class = get_called_class();
		while ($class = get_parent_class($class)) {
			$parent = get_class_vars($class);
			if (isset($parent['persistent_attributes'])) {
				static::$persistent_attributes = $parent['persistent_attributes'] + static::$persistent_attributes;
			}
		}
	}

	/**
	 * The names of attributes that have been retrieved for this instance.
	 * Used to discern unset values as these are invalid.
	 * @var array|null Only set on sparse retrieval!
	 */
	private $attributes_retrieved;

	/**
	 * Constructor is called late (after attributes are set)
	 * by PDO::FETCH_CLASS with $cast = true
	 *
	 * @param boolean $cast Regular construction should not cast (unset) attributes!
	 * @param array $attributes_retrieved Names of attributes that are set before construction in case of sparse retrieval
	 */
	public function __construct($cast = false, array $attributes_retrieved = null) {
		$this->attributes_retrieved = $attributes_retrieved;
		if ($attributes_retrieved == null) {
			// Cast all attributes
			$attributes_retrieved = $this->getAttributes();
		}
		if ($cast) {
			$this->castValues($attributes_retrieved);
		}
	}

	public function getTableName() {
		return static::$table_name;
	}

	/**
	 * Get all attribute names.
	 *
	 * @return array
	 */
	public function getAttributes() {
		return array_keys(static::$persistent_attributes);
	}

	/**
	 * @param $attribute_name
	 * @return array
	 */
	public function getAttributeDefinition($attribute_name) {
		return static::$persistent_attributes[$attribute_name];
	}

	public function getPrimaryKey() {
		return array_values(static::$primary_key);
	}

	public function getUUID() {
		return strtolower(implode('.', $this->getValues(true)) . '@' . get_class($this) . '.csrdelft.nl');
	}

	public function jsonSerialize() {
		$array = get_object_vars($this);
		$array['UUID'] = $this->getUUID();
		return $array;
	}

	/**
	 * Are there any attributes not yet retrieved?
	 *
	 * @param array $attributes to check for
	 * @return boolean
	 */
	public function isSparse(array $attributes = null) {
		if (!isset($this->attributes_retrieved)) {
			// Bookkeeping only in case of sparse retrieval
			return false;
		}
		if (empty($attributes)) {
			$attributes = $this->getAttributes();
		}
		return array_intersect($attributes, $this->attributes_retrieved) !== $attributes;
	}

	public function onAttributesRetrieved(array $attributes) {
		if (isset($this->attributes_retrieved)) {
			// Bookkeeping only in case of sparse retrieval
			$this->attributes_retrieved = array_merge($this->attributes_retrieved, $attributes);
		}
		$this->castValues($attributes); // PDO does not cast values automatically (yet)
	}

	/**
	 * Get the (non-sparse) attributes and their values of this object.
	 * Relies on getters and setters to update $attributes_retrieved
	 *
	 * @param boolean $primary_key_only
	 * @return array
	 */
	public function getValues($primary_key_only = false) {
		$values = array();
		if ($primary_key_only) {
			$attributes = $this->getPrimaryKey();
		} else {
			$attributes = $this->getAttributes();
		}
		// Do not return sparse attribute values as these are invalid
		if (isset($this->attributes_retrieved)) {
			$attributes = array_intersect($attributes, $this->attributes_retrieved);
		}
		foreach ($attributes as $attribute) {
			$values[$attribute] = Util::pdo_bool($this->$attribute);
		}
		if ($primary_key_only) {
			return array_values($values);
		}
		return $values;
	}

	/**
	 * Cast values to defined type.
	 * PDO does not cast values automatically (yet).
	 *
	 * @param array $attributes Attributes to cast
	 * @throws Exception
	 */
	private function castValues(array $attributes) {
		foreach ($attributes as $attribute) {
			$definition = $this->getAttributeDefinition($attribute);
			if (isset($definition[1]) AND $definition[1] AND $this->$attribute === null) {
				// Do not cast allowed null fields
			} elseif ($definition[0] === T::Boolean) {
				$this->$attribute = (boolean)$this->$attribute;
			} elseif ($definition[0] === T::Integer) {
				$this->$attribute = (int)$this->$attribute;
			} elseif ($definition[0] === T::Float) {
				$this->$attribute = (float)$this->$attribute;
			} else {
				$this->$attribute = (string)$this->$attribute;
			}
			// If $definition comes from PersistentAttribute->toDefinition, $definition[2] is an array if the definition is an enum
			if (defined('DB_CHECK') AND DB_CHECK AND $definition[0] === T::Enumeration
				AND !in_array($this->$attribute, is_array($definition[2]) ? $definition[2] : $definition[2]::getTypeOptions())) {
				throw new Exception(static::$table_name . '.' . $attribute . ' invalid ' . $definition[2] . '.enum value: "' . $this->$attribute . '"');
			}
		}
	}

	/**
	 * Check for differences in persistent attributes.
	 *
	 * @unsupported INDEX check; FOREIGN KEY check;
	 */
	public static function checkTable() {
		$database_admin = DatabaseAdmin::instance();
		$class = get_called_class();
		/** @var PersistentAttribute[] $attributes */
		$attributes = array();
		foreach (static::$persistent_attributes as $name => $definition) {
			$attributes[$name] = new PersistentAttribute($name, $definition);
			if (in_array($name, static::$primary_key)) {
				$attributes[$name]->key = 'PRI';
			} else {
				$attributes[$name]->key = '';
			}
		}
		try {
			$table_attributes = $database_admin->sqlDescribeTable(static::$table_name);
			/** @var PersistentAttribute[] $database_attributes */
			$database_attributes = array();
			foreach ($table_attributes as $attribute) {
				$database_attributes[$attribute->field] = $attribute; // overwrite existing
			}
		} catch (Exception $e) {
			if (Util::ends_with($e->getMessage(), static::$table_name . "' doesn't exist")) {
				$database_admin->sqlCreateTable(static::$table_name, $attributes, static::$primary_key);
				return;
			} else {
				throw $e; // Rethrow to controller
			}
		}
		// Rename attributes
		if (property_exists($class, 'rename_attributes')) {
			$rename = static::$rename_attributes;
			foreach ($rename as $old_name => $new_name) {
				if (property_exists($class, $new_name)) {
					$database_admin->sqlChangeAttribute(static::$table_name, $attributes[$new_name], $old_name);
				}
			}
		} else {
			$rename = array();
		}
		$previous_attribute = null;
		foreach (static::$persistent_attributes as $name => $definition) {
			// Add missing persistent attributes
			if (!isset($database_attributes[$name])) {
				if (!isset($rename[$name])) {
					$database_admin->sqlAddAttribute(static::$table_name, $attributes[$name], $previous_attribute);
				}
			} else {
				// Check existing persistent attributes for differences
				$diff = false;
				if ($attributes[$name]->type !== $database_attributes[$name]->type) {
					if ($definition[0] === T::Enumeration) {
						/** @var PersistentEnum $enum */
						$enum = $definition[2];
						if ($database_attributes[$name]->type !== "enum('" . implode("','", $enum::getTypeOptions()) . "')") {
							$diff = true;
						}
					} else {
						$diff = true;
					}
				}
				if ($attributes[$name]->null !== $database_attributes[$name]->null) {
					$diff = true;
				}
				// Cast database value if default value is defined
				if ($attributes[$name]->default !== null) {
					if ($definition[0] === T::Boolean) {
						$database_attributes[$name]->default = (boolean)$database_attributes[$name]->default;
					} elseif ($definition[0] === T::Integer) {
						$database_attributes[$name]->default = (int)$database_attributes[$name]->default;
					} elseif ($definition[0] === T::Float) {
						$database_attributes[$name]->default = (float)$database_attributes[$name]->default;
					}
				}
				if ($attributes[$name]->default !== $database_attributes[$name]->default) {
					$diff = true;
				}
				if ($attributes[$name]->extra !== $database_attributes[$name]->extra) {
					$diff = true;
				}
				// TODO: support other key types: MUL, UNI, etc.
				if ($attributes[$name]->key !== $database_attributes[$name]->key AND ($attributes[$name]->key === 'PRI' OR $database_attributes[$name]->key === 'PRI')) {
					$diff = true;
				}
				if ($diff) {
					$database_admin->sqlChangeAttribute(static::$table_name, $attributes[$name]);
				}
			}
			$previous_attribute = $name;
		}
		// Remove non-persistent attributes
		foreach ($database_attributes as $name => $attribute) {
			if (!isset(static::$persistent_attributes[$name]) AND !isset($rename[$name])) {
				$database_admin->sqlDeleteAttribute(static::$table_name, $attribute);
			}
		}
	}

}
