<?php
/** 
 * Extension that creates translated form fields for files
 * @author bummzack
 */
class TranslatedFile extends DataExtension
{
	/**
	 * Convenience method to use within a locale context.
	 * Eg. by specifying the edit fields with the UploadField.
	 * <code>
	 * $imageUpload = UploadField::create('Image');
	 * $imageUpload->setFileEditFields('getUploadEditorFields');
	 * </code>
	 * @return FieldList
	 */
	public function getUploadEditorFields(){
		$fields = FieldList::create();
		$translatedFields = TranslatableDataObject::get_localized_class_fields($this->owner->class);
		$transformation = null;
		$defaultLocale = Translatable::default_locale();
		if($defaultLocale != Translatable::get_current_locale()) {
			$transformation = TranslatableFormFieldTransformation::create($this->owner);
		}
		
		foreach ($translatedFields as $fieldName){
			// create the field in the default locale
			$field = $this->owner->getLocalizedFormField($fieldName, $defaultLocale);
			// use translated title if available
			$field->setTitle(_t('File.' . $fieldName, $fieldName));
			
			// if not in the default locale, we apply the form field transformation to the field
			if($transformation){
				$field = $transformation->transformFormField($field);
			}
			
			$fields->push($field);
		}
		return $fields;
	}
	
	/**
	 * Update the field values for the files & images section
	 * @see DataExtension::updateCMSFields()
	 */
	function updateCMSFields(FieldList $fields) {
		// only apply the update to files (not folders)
		if($this->owner->class != 'Folder'){
			// remove all the translated fields
			$translatedFields = TranslatableDataObject::get_localized_class_fields($this->owner->class);
			if($translatedFields){
				foreach($translatedFields as $fieldName){
					$fields->removeByName($fieldName, true);
				}
			}
			
			// add the tabs from the translatable tab set to the fields
			$set = $this->owner->getTranslatableTabSet();
			foreach($set->FieldList() as $tab){
				$fields->addFieldToTab('Root', $tab);
			}
		}
	}
}