translatable-dataobject
============

An extension for SilverStripe 3.0 that adds translations of fields to DataObjects.
Instead of creating new rows for translations, translations are added as columns. This way, there's only one
DataObject instance which is consistent across all localizations, but which has localized fields.

This module requires the [translatable module](https://github.com/silverstripe/silverstripe-translatable) to be installed.
Credit goes to Uncle Cheese which inspired my with his [TranslatableDataObject](http://www.leftandmain.com/silverstripe-tips/2012/04/03/translatabledataobject-insanely-simple-translation/)


Requirements
------------

 - [translatable module](https://github.com/silverstripe/silverstripe-translatable)


Installation
------------

Clone/download this repository into a folder in your SilverStripe installation folder.


Usage
------------

### Defining content locales

The ideal/recommended way to define the locales for the modules is to set the allowed locales for the Translatable module.
That way, the available locales are consistent throughout the CMS.
Example:

    Translatable::set_allowed_locales(array('en_US', 'fr_FR', 'de_DE'));

If you would like to set the locales for the `translatable-dataobject` module manually/separately, you can do the following:

    TranslatableDataObject::set_locales(array('en_US', 'fr_FR'));

If both of these calls are being omitted, the module will get the locales from the site content using:

    Translatable::get_existing_content_languages()

Using this setup requires you to run `dev/build` whenever you add a new translation language to the system though.

### Enabling translations

To make a DataObject translatable, a single line in `mysite/_config.php` is sufficient:

    Object::add_extension('MyDataObject', 'TranslatableDataObject');

Run `dev/build` afterwards, so that the additional DB fields can be created.
By default, all `Varchar`, `Text` and `HTMLText` fields will be translated, while all other fields remain untouched.
To alter these default-fields, you can configure them like this:

    // only translate Varchar and HTMLText fields
    TranslatableDataObject::set_default_fieldtypes(array('Varchar', 'HTMLText'));

If you would like to specify the fields to localize manually, there's an extended syntax for `add_extension`. Eg.

    // only translate the 'Title' and 'Content' field of "MyDataObject"
    Object::add_extension('MyDataObject', "TranslatableDataObject('Title','Content')");

Alternatively, you can also set the fields to translate in a static field on your DataObject. So inside your `MyDataObject` 
class you could add something like this:

    // create translatable fields for 'Title' and 'Content'
    public static $translatable_fields = array(
        'Title', 'Content'
    );

Limitations
------------

Since this extension adds more fields to a table, it is important to note that the number of localized fields and
the number of languages could cause problems with the underlying database. Imagine a DataObject with 10 localizable fields
and a site that will be translated into 5 other languages. This would add 50 columns to the table.

According to the MySQL documentation, the hard-limit of columns for a MySQL table is at `4096`, which should be sufficient 
for most setups. Other RDBMS might have other limitations though.