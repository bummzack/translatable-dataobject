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
			$transformation = TranslatableFormFieldTransformation::create($this->owner);
			
			// iterate through all localized fields
			foreach (self::$collectorCache[$this->owner->class] as $translatableField => $type) {
				
				if (strpos($translatableField, Translatable::get_current_locale())) {
					$basename = $this->getBasename($translatableField);
					
					if($field = $this->getLocalizedFormField($basename, Translatable::default_locale())){
						$fields->replaceField($basename, $transformation->transformFormField($field));
					}
				}
			} 
		}
	}
	
	/**
	 * Get a tabset with a tab for every language containing the translatable fields.
	 * Example usage:
	 * <code>
	 *     public function getCMSFields(){
	 *         $fields = FieldList::create();
	 *         $fields->add($this->getTranslatableTabSet());
	 *         return $fields;
	 *     }
	 * </code>
	 * @param string $title the title of the tabset to return. Defaults to "Root"
	 * @param bool $showNativeFields whether or not to show native tab labels (eg. Español instead of Spanish)
	 * @return TabSet
	 */
	public function getTranslatableTabSet($title = 'Root', $showNativeFields = true){
		$set = TabSet::create($title);
		
		// get target locales
		$locales = self::get_target_locales();
		
		// get translated fields
		$fieldNames = self::get_localized_class_fields($this->owner->class);
		
		if(!$fieldNames){
			user_error('No localized fields for the given object found', E_USER_WARNING);
		}
		
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
			if(!$this->canTranslate(null, $locale)){
				continue;
			}
			$lang = i18n::get_language_name(i18n::get_lang_from_locale($locale), $showNativeFields);
			if(!$lang){
				// fallback if get_lang_name doesn't return anything for the language code
				$lang = i18n::get_language_name($locale, $showNativeFields);
			}
			
			$langName = ucfirst(html_entity_decode($lang, ENT_NOQUOTES, 'UTF-8'));
			
			if(isset($ambiguity[$locale])){
				$langName .= ' (' . $ambiguity[$locale] . ')';
			}
			$tab = Tab::create($locale, $langName);
			
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
		
		if(!$this->canTranslate(null, $locale)){
			// if not allowed to translate, return the field as Readonly
			return ReadonlyField::create($localizedFieldName, $baseName);
		}
		
		$dbFields = array();
		Config::inst()->get($this->owner->class, 'db', Config::EXCLUDE_EXTRA_SOURCES, $dbFields);
		
		$type = isset($dbFields[$baseName]) ? $dbFields[$baseName] : '';
		$typeClean = (($p = strpos($type, '(')) !== false) ? substr($type, 0, $p) : $type;
		$field = null;
		
		switch ($typeClean) {
			case 'Varchar':
			case 'HTMLVarchar':
				$field = TextField::create($localizedFieldName, $baseName);
				break;
			case 'Text':
				$field = TextareaField::create($localizedFieldName, $baseName);
				break;
			case 'HTMLText':
			default:
				$field = HtmlEditorField::create($localizedFieldName, $baseName);
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
	public function T($field, $strict = true, $parseShortCodes = false) {
		return $this->getLocalizedValue($field, $strict, $parseShortCodes);
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
		$fields = self::get_localized_class_fields($this->ownerBaseClass);
		return in_array($fieldName, $fields);
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
	 * @param boolean $parseShortCodes whether or not the value should be parsed with the shortcode parser
	 */
	public function getLocalizedValue($fieldName, $strict = true, $parseShortCodes = false){
		$localizedField = $this->getLocalizedFieldName($fieldName);
		
		$value = $this->owner->dbObject($localizedField);
		if(!$strict && !$value){
			$value = $this->owner->dbObject($fieldName);
		}
		
		return ($parseShortCodes && $value) ? ShortcodeParser::get_active()->parse($value) : $value;
	}
	
	/**
	 * Check whether or not the given member is allowed to edit the given locale
	 * Caution: Does not consider the {@link canEdit()} permissions.
	 * 
	 * @param Member $member
	 * @param string $locale
	 * @return boolean
	 */
	public function canTranslate($member, $locale) 
	{
		if($locale && !i18n::validate_locale($locale)) {
			throw new InvalidArgumentException(sprintf('Invalid locale "%s"', $locale));
		}
		
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) $member = Member::currentUser();

		// check for locale
		$allowedLocale = (
			!is_array(Translatable::get_allowed_locales()) 
			|| in_array($locale, Translatable::get_allowed_locales())
		);

		if(!$allowedLocale) return false;
		
		// By default, anyone who can edit a page can edit the default locale
		if($locale == Translatable::default_locale()) return true;
		
		// check for generic translation permission
		if(Permission::checkMember($member, 'TRANSLATE_ALL')) return true;
		
		// check for locale specific translate permission
		if(!Permission::checkMember($member, 'TRANSLATE_' . $locale)) return false;
		
		return true;
	}
	
	/**
	 * On before write hook. 
	 * Check if any translatable field has changed and if permissions are sufficient
	 * @see DataExtension::onBeforeWrite()
	 */
	public function onBeforeWrite() 
	{
		if(!isset(self::$localizedFields[$this->ownerBaseClass])){
			return;
		}
		
		$fields = self::$localizedFields[$this->ownerBaseClass];
		foreach($fields as $field => $localized){
			foreach(self::get_target_locales() as $locale){
				$fieldName = self::localized_field($field, $locale);
				if($this->owner->isChanged($fieldName, 2) && !$this->canTranslate(null, $locale)){
					throw new PermissionFailureException(
						"You're not allowed to edit the locale '$locale' for this object");
				}
			}
		}
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
	 * Get an array with all localized fields for the given class
	 * @param string $class the class name to get the fields for
	 * @return array containing all the localized field names
	 */
	public static function get_localized_class_fields($class){
		$fieldNames = null;
		$ancestry = array_reverse(ClassInfo::ancestry($class));
		foreach ($ancestry as $className){
			if(isset(self::$localizedFields[$className])){
				if($fieldNames === null){
					$fieldNames = array();
				}
				foreach (self::$localizedFields[$className] as $k => $v){
					$fieldNames[] = $k;
				}
			}
		}
		return array_unique($fieldNames);
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
