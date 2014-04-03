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
	
	// lock to prevent endless loop
	protected static $collectorLock = array();
	
	/**
	 * Use table information and locales to dynamically build required table fields
	 * @see DataExtension::get_extra_config()
	 */
	public static function get_extra_config($class, $extension, $args) {
		if($args){
			self::$arguments[$class] = $args;
		}
		
		return array (
			'db' => self::collectDBFields($class)
		);
	}
	
	/**
	 * Alter the CMS Fields in order to automatically present the 
	 * correct ones based on current language.
	 */
	public function updateCMSFields(FieldList $fields) {
		parent::updateCMSFields($fields);
		
		if(!isset(self::$collectorCache[$this->owner->class])){
			return;
		}
		
		// remove all localized fields from the list (generated through scaffolding)
		foreach (self::$collectorCache[$this->owner->class] as $translatableField => $type) {
			$fields->removeByName($translatableField);
		}
		
		// check if we're in a translation
		if (Translatable::default_locale() != Translatable::get_current_locale()) {
			$transformation = new TranslatableFormFieldTransformation($this->owner);

			// iterate through all localized fields
			foreach (self::$collectorCache[$this->owner->class] as $translatableField => $type) {
				
				if (strpos($translatableField, Translatable::get_current_locale())) {
					$basename = $this->getBasename($translatableField);
					
					$field = $this->getLocalizedFormField($basename, Translatable::default_locale());
					$fields->replaceField($basename, $transformation->transformFormField($field));
				}
			} 
		}
	}
	
	/**
	 * Get a tabset with a tab for every language containing the translatable fields.
	 * Example usage:
	 * <code>
	 *     public function getCMSFields(){
	 *         $fields = new FieldList();
	 *         $fields->add($this->getTranslatableTabSet());
	 *         return $fields;
	 *     }
	 * </code>
	 * @param string $title the title of the tabset to return. Defaults to "Root"
	 * @return TabSet
	 */
	public function getTranslatableTabSet($title = 'Root'){
		$set = new TabSet($title);
		
		// get target locales
		$locales = self::get_target_locales();
		
		// get translated fields
		$fieldNames = array_keys(self::$localizedFields[$this->owner->class]);
		
		$ambiguity = array();
		foreach($locales as $locale){
			$langCode = i18n::get_lang_from_locale($locale);
			foreach($locales as $l){
				if($l != $locale && i18n::get_lang_from_locale($l) == $langCode){
					$parts = explode('_', $l);
					$localePart = end($parts);
					$ambiguity[$l] = $localePart;
				}
			}
		}
		
		foreach($locales as $locale){
			$langName = ucfirst(html_entity_decode(
				i18n::get_language_name(i18n::get_lang_from_locale($locale), true),
				ENT_NOQUOTES, 'UTF-8'));
			
			if(isset($ambiguity[$locale])){
				$langName .= ' (' . $ambiguity[$locale] . ')';
			}
			$tab = new Tab($locale, $langName);
			
			foreach ($fieldNames as $fieldName) {
				$tab->push($this->getLocalizedFormField($fieldName, $locale));
			}
			
			$set->push($tab);
		}
		return $set;
	}
	
	/**
	 * Get a form field for the given field name
	 * @param string $fieldName
	 * @param string $locale
	 * @return FormField
	 */
	public function getLocalizedFormField($fieldName, $locale){
		$baseName = $this->getBasename($fieldName);
		$localizedFieldName = self::localized_field($fieldName, $locale);
		
		$dbFields = array();
		Config::inst()->get($this->owner->class, 'db', Config::EXCLUDE_EXTRA_SOURCES, $dbFields);
		
		$type = isset($dbFields[$baseName]) ? $dbFields[$baseName] : '';
		$typeClean = (($p = strpos($type, '(')) !== false) ? substr($type, 0, $p) : $type;
		$field = null;
		
		switch ($typeClean) {
			case 'Varchar':
			case 'HTMLVarchar':
				$field = new TextField($localizedFieldName, $baseName);
				break;
			case 'Text':
				$field = new TextareaField($localizedFieldName, $baseName);
				break;
			case 'HTMLText':
			default:
				$field = new HtmlEditorField($localizedFieldName, $baseName);
				break;
		}
		return $field;
	}

	/**
	 * A template accessor used to get the translated version of a given field.
	 * Does the same as @see getLocalizedValue
	 *
	 * ex: $T(Description) in the locale it_IT returns $yourClass->getField('Description__it_IT');
	 */
	public function T($field, $strict = true) {
		return $this->getLocalizedValue($field, $strict);
	}
	
	/**
	 * Present translatable form fields in a more readable fashion
	 * @see DataExtension::updateFieldLabels()
	 */
	public function updateFieldLabels(&$labels) {
		parent::updateFieldLabels($labels);
		
		$statics = self::$collectorCache[$this->ownerBaseClass];
		foreach($statics as $field => $type){
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
	 * @param boolean $strict if false, this will fallback to the master version of the field!
	 */
	public function getLocalizedValue($fieldName, $strict = true){
		$localizedField = $this->getLocalizedFieldName($fieldName);
		
		if($strict){
			return $this->owner->getField($localizedField);
		}
		
		// if not strict, check localized first and fallback to fieldname
		if($value = $this->owner->getField($localizedField)){
			return $value;
		}
		
		return $this->owner->getField($fieldName);
	}
	
	/**
	 * Given a translatable field name, pull out the locale and
	 * return the raw field name.
	 *
	 * ex: "Description__fr_FR" -> "Description"
	 *
	 * @param string $field The name of the translated field
	 * @return string
	 */
	protected function getBasename($field) {
		$retVal = explode(TRANSLATABLE_COLUMN_SEPARATOR, $field);
		return reset($retVal);
	}
	
	/**
	 * Given a translatable field name, pull out the raw field name and
	 * return the locale
	 *
	 * ex: "Description__fr_FR" -> "fr_FR"
	 *
	 * @param string $field The name of the translated field
	 * @return string
	 */
	protected function getLocale($field) {
		$retVal = explode(TRANSLATABLE_COLUMN_SEPARATOR, $field);
		return end($retVal);
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
	
	/**
	 * Collect all additional database fields of the given class.
	 * @param string $class
	 */
	protected static function collectDBFields($class){
		if(isset(self::$collectorCache[$class])){
			return self::$collectorCache[$class];
		}
	
		if(isset(self::$collectorLock[$class]) && self::$collectorLock[$class]){
			return null;
		}
		self::$collectorLock[$class] = true;
	
		// Get all DB Fields
		$fields = array();
		Config::inst()->get($class, 'db', Config::EXCLUDE_EXTRA_SOURCES, $fields);
	
		// Get all arguments
		$arguments = self::get_arguments($class);
	
		$locales = self::get_target_locales();
	
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
		self::$collectorLock[$class] = false;
		return $additionalFields;
	}
	
	/**
	 * Get the locales that should be translated
	 * @return array containing the locales to use
	 */
	protected static function get_target_locales(){
		// if locales are explicitly set, use these
		if(is_array(self::$locales)){
			return self::$locales;
			// otherwise check the allowed locales. If these have been set, use these
		} else if(Translatable::get_allowed_locales() !== null){
			return Translatable::get_allowed_locales();
		} else {
			// last resort is to take the existing content languages
			return array_keys(Translatable::get_existing_content_languages());
		}
	}
	
	/**
	 * Get the custom arguments for a given class. Either directly from how the extension
	 * was defined, or lookup the 'translatable_fields' static variable
	 * 
	 * @param string $class
	 * @return array|null
	 */
	protected static function get_arguments($class){
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
