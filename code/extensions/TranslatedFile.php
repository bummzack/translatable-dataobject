<?php
/**
 * Extension that creates translated form fields for files
 * @author bummzack
 */
class TranslatedFile extends DataExtension
{
    /**
     * Create an UploadField that can be used in a translation context.
     * Attaching, deleting, sorting will only be allowed in the master language.
     * If the page is translated, only allow editing of file title/content (eg. translate)
     * @param string $name the field name
     * @param SS_List $collection the file collection
     * @param string|null $title the field label (title)
     * @param string|null $sortField field to sort items on (in translation context). Set this
     *  parameter to null if there's no sorting
     * @return UploadField
     */
    public static function translatable_uploadfield($name, SS_List $collection, $title = null, $sortField = 'SortOrder')
    {
        // create two different upload fields, depending on locale
        if (Translatable::default_locale() == Translatable::get_current_locale()) {
            // for the master language, create a regular sortable upload field
            if (class_exists('SortableUploadField')) {
                /** @var SortableUploadField $uploadField */
                $uploadField = SortableUploadField::create($name, $title, $collection);
            } else {
                /** @var UploadField $uploadField */
                $uploadField = UploadField::create($name, $title, $collection);
            }
        } else {
            $fieldName = 'Translate' . $name;
            // for all other languages, access the files in read-only
            if ($sortField && $collection instanceof SS_Sortable){
                /** @var UploadField $uploadField */
                $uploadField = UploadField::create($fieldName, $title, $collection->sort($sortField));
            } else {
                /** @var UploadField $uploadField */
                $uploadField = UploadField::create($fieldName, $title, $collection);
            }
            // prevent uploads
            $uploadField->setConfig('canUpload', false);
            // prevent attaching
            $uploadField->setConfig('canAttachExisting', false);
            // use a custom button-template with only a edit-button
            $uploadField->setTemplateFileButtons('UploadField_TranslationButtons');
        }

        $uploadField->setFileEditFields('getUploadEditorFields');

        return $uploadField;
    }

    /**
     * Convenience method to use within a locale context.
     * Eg. by specifying the edit fields with the UploadField.
     * <code>
     * $imageUpload = UploadField::create('Image');
     * $imageUpload->setFileEditFields('getUploadEditorFields');
     * </code>
     * @return FieldList
     */
    public function getUploadEditorFields()
    {
        /** @var FieldList $fields */
        $fields = FieldList::create();
        $translatedFields = TranslatableDataObject::get_localized_class_fields($this->owner->class);
        $transformation = null;
        $defaultLocale = Translatable::default_locale();
        if ($defaultLocale != Translatable::get_current_locale()) {
            /** @var TranslatableFormFieldTransformation $transformation */
            $transformation = TranslatableFormFieldTransformation::create($this->owner);
        }

        foreach ($translatedFields as $fieldName) {
            // create the field in the default locale
            /** @var FormField $field */
            $field = $this->owner->getLocalizedFormField($fieldName, $defaultLocale);
            // use translated title if available
            $field->setTitle(_t('File.' . $fieldName, $fieldName));

            // if not in the default locale, we apply the form field transformation to the field
            if ($transformation) {
                $field = $transformation->transformFormField($field);
            }

            $fields->push($field);
        }
        return $fields;
    }

    /**
     * Update the field values for the files & images section
     * @see DataExtension::updateCMSFields()
     * @inheritdoc
     */
    public function updateCMSFields(FieldList $fields)
    {
        // only apply the update to files (not folders)
        if ($this->owner->class != 'Folder') {
            // remove all the translated fields
            $translatedFields = TranslatableDataObject::get_localized_class_fields($this->owner->class);
            if (!empty($translatedFields)) {
                foreach ($translatedFields as $fieldName) {
                    $fields->removeByName($fieldName, true);
                }
            }

            // add the tabs from the translatable tab set to the fields
            /** @var TabSet $set */
            $set = $this->owner->getTranslatableTabSet();
            foreach ($set->FieldList() as $tab) {
                $fields->addFieldToTab('Root', $tab);
            }
        }
    }
}
