<?php

class TranslatableFormFieldTransformation extends FormTransformation
{

    /**
     * @var DataObject
     */
    private $original = null;

    public function __construct(DataObject $original)
    {
        $class = $original->class;

        if (!$class::has_extension('TranslatableDataObject')) {
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
    public function getOriginal()
    {
        return $this->original;
    }

    /**
     * Transform a given form field into a composite field, where the translation is editable and the original value
     * is added as a read-only field.
     * @param FormField $field
     * @return CompositeField
     */
    public function transformFormField(FormField $field)
    {
        $newfield = $field->performReadOnlyTransformation();

        $fieldname = $field->getName();
        if ($this->original->isLocalizedField($fieldname)) {
            $field->setName($this->original->getLocalizedFieldName($fieldname));
            $field->setValue($this->original->getLocalizedValue($fieldname));
        }

        return $this->baseTransform($newfield, $field, $fieldname);
    }

    /**
     * @param FormField $nonEditableField
     * @param FormField $originalField
     * @param string $fieldname
     * @return CompositeField
     */
    protected function baseTransform($nonEditableField, $originalField, $fieldname)
    {
        /** @var CompositeField $nonEditableField_holder */
        $nonEditableField_holder = CompositeField::create($nonEditableField);
        $nonEditableField_holder->setName($fieldname . '_holder');
        $nonEditableField_holder->addExtraClass('originallang_holder');
        $nonEditableField->setValue($this->original->$fieldname);
        $nonEditableField->setName($fieldname . '_original');
        $nonEditableField->addExtraClass('originallang');
        $nonEditableField->setTitle(_t(
            'Translatable_Transform.OriginalFieldLabel',
            'Original {title}',
            'Label for the original value of the translatable field.',
            array('title' => $originalField->Title())
        ));

        $nonEditableField_holder->insertBefore($originalField, $fieldname . '_original');
        return $nonEditableField_holder;
    }
}
