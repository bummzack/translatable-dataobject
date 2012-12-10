<?php
/**
 * Translatable extension, inspired by Uncle Cheese's implementation
 * https://github.com/unclecheese/TranslatableDataObject but tailored to be used
 * with the translatable SilverStripe extension
 * 
 * @author bummzack
 */
class TranslatableDataObject extends DataExtension
{
	// Field types that should be translated if no specific fields are given
	protected static $default_field_types = array(
		'Varchar',
		'Text',
		'HTMLText'	
	);
	
	// configuration arguments for each class
	protected static $arguments = array();
	
	// locales to build
	protected static $locales = null;
	
	// cache of the collector calls
	protected static $collectorCache = array();
	
	// cache of classes and their localized fields
	protected static $localizedFields = array();
	
	/**
	 * Use table information and locales to dynamically build required table fields
	 * @see DataExtension::extraStatics()
	 */
	public function extraStatics($class = null, $extension = null) {
		if($class == null){
			$class = $this->ownerBaseClass;
		}
		return array (
			'db' => $this->collectDBFields($class)
		);
	}
	
	/**
	 * A template accessor used to get the translated version of a given field
	 *
	 * ex: $T(Description) in the locale it_IT returns $yourClass->getField('Description__it_IT');
	 *
	 * @param string $field The field name to translate
	 * @param boolean $strict if true, this won't fallback to the master version of the field!
	 * @return string
	 */
	public function T($field, $strict = true) {
		$localizedField = self::localized_field($field);
		if($strict){
			return $this->getLocalizedValue($field);
		}
		return $this->owner->hasField($localizedField) 
			 ? $this->owner->getField($localizedField) : $this->owner->getField($field);
	}
	
	/**
	 * Present translatable form fields in a more readable fashion
	 * @see DataExtension::updateFieldLabels()
	 */
	public function updateFieldLabels(&$labels) {
		parent::updateFieldLabels($labels);
		
		$statics = $this->extraStatics();
		foreach($statics['db'] as $field => $type){
			$parts = explode(TRANSLATABLE_COLUMN_SEPARATOR, $field);
			$labels[$field] = FormField::name_to_label($parts[0]) . ' (' . $parts[1] . ')';
		}
	}
	
	
	/**
	 * Check if the given field name is a localized field
	 * @param string $fieldName the name of the field without any locale extension. Eg. "Title"
	 */
	public function isLocalizedField($fieldName){
		return isset(self::$localizedFields[$this->ownerBaseClass][$fieldName]);
	}
	
	/**
	 * Get the field name in the current reading locale
	 * @param string $fieldName the name of the field without any locale extension. Eg. "Title"
	 * @return void|string
	 */
	public function getLocalizedFieldName($fieldName){
		if(!$this->isLocalizedField($fieldName)){
			trigger_error("Field '$fieldName' is not a localized field", E_USER_ERROR);
			return;
		}
		
		return self::localized_field($fieldName);
	}
	
	/**
	 * Get the localized value for a given field.
	 * @param string $fieldName the name of the field without any locale extension. Eg. "Title"
	 */
	public function getLocalizedValue($fieldName){
		$localizedField = $this->getLocalizedFieldName($fieldName);
		
		return $this->owner->getField($localizedField);
	}
	
	/**
	 * Collect all additional database fields of the given class.
	 * @param string $class
	 */
	protected function collectDBFields($class){
		if(isset(self::$collectorCache[$class])){
			return self::$collectorCache[$class];
		}
		
		// find the extension in the config (we do this to get the exact parameters)
		$extensions = Config::inst()->get($class, 'extensions', Config::EXCLUDE_EXTRA_SOURCES);
		$extensionString = null;
		foreach($extensions as $extension){
			if(substr($extension, 0, strlen($this->class)) == $this->class){
				$extensionString = $extension;
				break;
			}
		}
		
		// this is kind of a hack to prevent endless loops. Since DataObject::custom_database_fields
		// will trigger the "extraStatics" method, we have to remove the extension temporarily
		// to get all database fields (even the ones from other extensions), but excluding our own!
		Object::remove_extension($class, $extensionString);
		$fields = DataObject::custom_database_fields($class);
		$arguments = self::getArguments($class);
		Object::add_extension($class, $extensionString);
		
		$locales = null;
		
		// if locales are explicitly set, use these
		if(is_array(self::$locales)){
			$locales = self::$locales;
		// otherwise check the allowed locales. If these have been set, use these
		} else if(Translatable::get_allowed_locales() !== null){
			$locales = Translatable::get_allowed_locales();
		// last resort is to take the existing content languages
		} else {
			$locales = array_keys(Translatable::get_existing_content_languages());
		}
		
		// remove the default locale
		if(($index = array_search(Translatable::default_locale(), $locales)) !== false) {
			array_splice($locales, $index, 1);
		}
		
		// fields that should be translated
		$fieldsToTranslate = array();
		
		// validate the arguments
		if($arguments){
			foreach($arguments as $field){
				// only allow fields that are actually in our field list
				if(array_key_exists($field, $fields)){
					$fieldsToTranslate[] = $field;
				}
			}
		} else {
			// check for the given default field types and add all fields of that type
			foreach($fields as $field => $type){
				$typeClean = (($p = strpos($type, '(')) !== false) ? substr($type, 0, $p) : $type;
				if(in_array($typeClean, self::$default_field_types)){
					$fieldsToTranslate[] = $field;
				}
			}
		}
		
		
		// gather all the DB fields
		$additionalFields = array();
		self::$localizedFields[$class] = array();
		foreach($fieldsToTranslate as $field){
			self::$localizedFields[$class][$field] = array();
			foreach($locales as $locale){
				$localizedName = self::localized_field($field, $locale);
				self::$localizedFields[$class][$field][] = $localizedName;
				$additionalFields[$localizedName] = $fields[$field];
			}
		}
		self::$collectorCache[$class] = $additionalFields;
		return $additionalFields;
	}
	
	/**
	 * Given a field name and a locale name, create a composite string that represents
	 * the field in the database.
	 *
	 * @param string $field The field name
	 * @param string $locale The locale name
	 * @return string
	 */
	public static function localized_field($field, $locale = null) {
		if(!$locale){
			$locale = Translatable::get_current_locale();
		}
		if($locale == Translatable::default_locale()){
			return $field;
		}
		return $field . TRANSLATABLE_COLUMN_SEPARATOR . $locale;
	}
	
	/**
	 * Set the default field-types that should be translated. 
	 * Must be an array of valid field types. These types only come into effect when the
	 * fields that should be translatet *aren't* explicitly defined.
	 * 
	 * @example
	 * <code>
	 * // create translations for all fields of type "Varchar" and "HTMLText".
	 * TranslatableDataObject::set_default_fieldtypes(array('Varchar', 'HTMLText'));
	 * </code>
	 * 
	 * Defaults to: array('Varchar', 'Text', 'HTMLText')
	 * 
	 * @param array $types the field-types that should be translated if not explicitly set
	 */
	public static function set_default_fieldtypes($types){
		if(is_array($types)){
			self::$default_field_types = $types;
		}
	}
	
	/**
	 * Get the default field-types 
	 * @see TranslatableDataObject::set_default_fieldtypes
	 * @return array
	 */
	public static function get_default_fieldtypes(){
		return self::$default_field_types;
	}
	
	/**
	 * Explicitly set the locales that should be translated.
	 * 
	 * @example
	 * <code>
	 * // Set locales to en_US and fr_FR
	 * TranslatableDataObject::set_locales(array('en_US', 'fr_FR'));
	 * </code>
	 * 
	 * Defaults to `null`. In this case, locales are being taken from 
	 * Translatable::get_allowed_locales or Translatable::get_existing_content_languages
	 * 
	 * @param array $locales an array of locales or null
	 */
	public static function set_locales($locales){
		if(is_array($locales)){
			foreach($locales as $locale){
				if(i18n::validate_locale($locale)){
					if(!is_array(self::$locales)){
						self::$locales = array();
					}
					if(array_search($locale, self::$locales) === false){
						self::$locales[] = $locale;
					}
				}
			}
		} else {
			self::$locales = null;
		}
	}
	
	/**
	 * Get the list of locales that should be translated.
	 * @return array array of locales if they have been defined using set_locales, null otherwise
	 */
	public static function get_locales(){
		return self::$locales;
	}
	
	public static function add_to_class($class, $extensionClass, $args = null) {
		// no need to add class multiple times
		if(array_key_exists($class, self::$arguments)){
			return;
		}
		
		// check if custom fields have been defined. If yes store arguments for later usage
		if($args && is_array($args)){
			self::$arguments[$class] = $args;
		}
	}
	
	/**
	 * Get the custom arguments for a given class. Either directly from how the extension
	 * was defined, or lookup the 'translatable_fields' static variable
	 * 
	 * @param string $class
	 * @return array|null
	 */
	protected static function getArguments($class){
		if(isset(self::$arguments[$class])){
			return self::$arguments[$class];
		} else {
			if($staticFields = Config::inst()->get($class, 'translatable_fields', Config::FIRST_SET)){
				if(is_array($staticFields) && !empty($staticFields)){
					return $staticFields;
				}
			}
		}
		
		return null;
	}
}