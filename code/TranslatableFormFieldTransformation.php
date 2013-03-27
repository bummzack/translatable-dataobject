<?php
class TranslatableFormFieldTransformation extends FormTransformation {

	/**
	 * @var DataObject
	 */
	private $original = null;

	function __construct(DataObject $original) {
		$class = $original->class;
		if(!$class::has_extension('TranslatableDataObject')){
			trigger_error(
				"Parameter given does not have the required 'TranslatableDataObject' extension", E_USER_ERROR);
		}
		$this->original = $original;
		parent::__construct();
	}

	/**
	 * Returns the original DataObject attached to the Transformation
	 *
	 * @return DataObject
	 */
	function getOriginal() {
		return $this->original;
	}

	function transformFormField(FormField $field) {
		$newfield = $field->performReadOnlyTransformation();
		
		$fieldname = $field->getName();
		if($this->original->isLocalizedField($fieldname)){
			$field->setName($this->original->getLocalizedFieldName($fieldname));
			$field->setValue($this->original->getLocalizedValue($fieldname));
		}
		
		return $this->baseTransform($newfield, $field, $fieldname);
	}

	protected function baseTransform($nonEditableField, $originalField, $fieldname) {
		$nonEditableField_holder = new CompositeField($nonEditableField);
		$nonEditableField_holder->setName($fieldname.'_holder');
		$nonEditableField_holder->addExtraClass('originallang_holder');
		$nonEditableField->setValue($this->original->$fieldname);
		$nonEditableField->setName($fieldname.'_original');
		$nonEditableField->addExtraClass('originallang');
		$nonEditableField->setTitle('Original '.$originalField->Title());

		$nonEditableField_holder->insertBefore($originalField, $fieldname.'_original');
		return $nonEditableField_holder;
	}
}
