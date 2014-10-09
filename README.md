translatable-dataobject
============

An extension for SilverStripe 3.0 or newer that adds translations of fields to DataObjects.
Instead of creating new rows for translations, translations are added as columns. This way, there's only one
DataObject instance which is consistent across all localizations, but which has localized fields.

This module requires the [translatable module](https://github.com/silverstripe/silverstripe-translatable) to be installed.
Credit goes to Uncle Cheese which inspired my with his [TranslatableDataObject](http://www.leftandmain.com/silverstripe-tips/2012/04/03/translatabledataobject-insanely-simple-translation/)


Requirements
------------

 - [SilverStripe 3.0 or newer](http://www.silverstripe.org/stable-download/)
 - [translatable module](https://github.com/silverstripe/silverstripe-translatable)


Installation
------------
Use [composer](https://getcomposer.org/):

    composer require bummzack/translatable-dataobject dev-master

Alternatively clone/download this repository into a folder in your SilverStripe installation folder.


Usage
------------

### Defining content locales

The ideal/recommended way to define the locales for the modules is to set the allowed locales for the Translatable module.
That way, the available locales are consistent throughout the CMS.
Example:

```php
Translatable::set_allowed_locales(array('en_US', 'fr_FR', 'de_DE'));
```

If you would like to set the locales for the `translatable-dataobject` module manually/separately, you can do the following:

```php
TranslatableDataObject::set_locales(array('en_US', 'fr_FR'));
```

If both of these calls are being omitted, the module will get the locales from the site content using:

```php
Translatable::get_existing_content_languages()
```

Using this setup requires you to run `dev/build` whenever you add a new translation language to the system though.

### Enabling translations

To make a DataObject translatable, a single line in `mysite/_config.php` is sufficient:

```php
// SilverStripe 3.1 syntax
MyDataObject::add_extension('TranslatableDataObject');

// SilverStripe 3.0 syntax
Object::add_extension('MyDataObject', 'TranslatableDataObject');
```

Run `dev/build` afterwards, so that the additional DB fields can be created.
By default, all `Varchar`, `Text` and `HTMLText` fields will be translated, while all other fields remain untouched.
To alter these default-fields, you can configure them like this:

```php
// only translate Varchar and HTMLText fields
TranslatableDataObject::set_default_fieldtypes(array('Varchar', 'HTMLText'));
```

If you would like to specify the fields to localize manually, there's an extended syntax for `add_extension`. Eg.

```php
// only translate the 'Title' and 'Content' field of "MyDataObject"
// SilverStripe 3.1 syntax
MyDataObject::add_extension("TranslatableDataObject('Title','Content')");

// SilverStripe 3.0 syntax
Object::add_extension('MyDataObject', "TranslatableDataObject('Title','Content')");
```

Alternatively, you can also set the fields to translate in a static field on your DataObject. So inside your `MyDataObject` 
class you could add something like this (please replace `private` with `public` if you're still running SilverStripe 3.0.x):

```php
// create translatable fields for 'Title' and 'Content'
private static $translatable_fields = array(
    'Title', 'Content'
);
```

### Translations in the CMS

Imagine you have a `TestimonialPage` that `has_many` testimonials and you're managing these Testimonials in a `GridField`.

#### The DataObject (Testimonial)

Let's start with the `Testimonial` DataObject (**Note:** use `public` instead of `private` for the static members if you're still running SilverStripe 3.0.x):

```php
class Testimonial extends DataObject
{
    private static $db = array(
        'Title' => 'Varchar',
        'Content' => 'HTMLText'
    );

    private static $has_one = array(
        'TestimonialPage' => 'TestimonialPage'
    );

    private static $translatable_fields = array(
        'Title',
        'Content'
    );
}
```

Most of this should look familiar. There's a new static member called `$translatable_fields` which defines the fields that should be translated. In addition you'll also have to add `Testimonial::add_extension('TranslatableDataObject');` in `mysite/_config.php`. 

You could also omit the `$translatable_fields` and write `Testimonial::add_extension("TranslatableDataObject('Title','Content')");` in `mysite/_config.php` instead. Depending on the number of fields to translate, this could become unreadable and therefore you might prefer using `$translatable_fields`.

#### The page (TestimonialPage)

Now for the Testimonial-Page:

```php
class TestimonialPage extends Page
{
    private static $has_many = array(
        'Testimonials' => 'Testimonial' 
    );

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
    
        // manage testimonials
        $gridConfig = GridFieldConfig_RelationEditor::create();
        $gridField = new GridField('Testimonials', 'Testimonials', $this->Master()->Testimonials(), $gridConfig);
        $gridField->setModelClass('Testimonial');
        $fields->addFieldsToTab('Root.Testimonials', $gridField);
    
        return $fields;
    }
}

class TestimonialPage_Controller extends Page_Controller
{

}
```
    
This looks even more like a regular page and you probably wonder what's so special here. The only thing that changed is that we use `$this->Master()->Testimonials()` instead of `$this->Testimonials()` as the GridField datasource. With this setup, you should be able to switch between different languages in the CMS and edit the testimonials each in the current language. *Give it a try*

#### Forms and ModelAdmin

The `TranslatableDataObject` extension comes with several helper methods that will make it easier for you to build translatable forms for the CMS. The default behavior (if you don't implement `getCMSFields` yourself, also known as scaffolding) is that you'll only see the form fields for the currently active locale, which is ideal if you're working in the *Pages* section of the CMS where you're always working in one language tree. For locales other than the default locale, you'll see the original content as a read-only field below each form-field (same behavior as the translatable module provides for pages).

Of course you can also implement the `getCMSFields` method yourself. Here's an example:

```php
public function getCMSFields()
{
    $titleField = new TextField('Title');
    $contentField = new HtmlEditorField('Content');

    // transform the fields if we're not in the default locale
    if(Translatable::default_locale() != Translatable::get_current_locale()) {
        $transformation = new TranslatableFormFieldTransformation($this);
        $titleField = $transformation->transformFormField($titleField);
        $contentField = $transformation->transformFormField($contentField);
    }

    return new FieldList(
        $titleField,
        $contentField
    );
}
```

When we're not in the default locale, we transform the fields using a `TranslatableFormFieldTransformation` instance. This is very similar to what you're probably used to from the translatable module with its `Translatable_Transformation`. What this does is: It takes the given form-field and replaces it's name and content with the translated content. The original content will appear as *read-only* below the form field.

If you wish to get an input field for the current locale, there's a helper method for that called `getLocalizedFormField`. It will automatically create an appropriate input field for the given field name. So if your field is of type `Varchar`, you'll get a `TextField` instance. A `HTMLText` will return a `HtmlEditorField` instance etc. 

Example:

```php
public function getCMSFields()
{
    // get the current locale
    $locale = Translatable::get_current_locale();

    return new FieldList(
        $this->getLocalizedFormField('Title', $locale), 
        $this->getLocalizedFormField('Content', $locale)
    );
}
```

Using the `TranslatableFormFieldTransformation` class or the `getLocalizedFormField` method should provide enough tools to build custom backend forms for most of your needs. 

There's another helper method which is especially useful in a `ModelAdmin` context (because in *ModelAdmin* you're not working in one locale as it's the case with the *Pages* section). The helper method is called `getTranslatableTabSet` and will give you a `TabSet` with an individual Tab for every language. Here's how you use it:

```php
public function getCMSFields(){
    $fields = new FieldList();
    $fields->add($this->getTranslatableTabSet());
    return $fields;
}
```

Doing this will give you a tab for each language, each tab containing the translatable form fields. If you have fields that aren't being translated, yet still need to be edited via backend, do something along these lines:

```php
public function getCMSFields(){
    $fields = new FieldList();
    $fields->add($this->getTranslatableTabSet());

    // add all "Global" fields to another tab
    $fields->addFieldsToTab('Root.Global', array(
        new TextField('NotTranslatedField'),
        new UploadField('MyImage')
        // etc...
    ));

    return $fields;
}
```

### Files and Images

Usually you'll also want to translate some fields of the file class. Enabling translation is simple by adding the following to `mysite/_config.php`:

```php
// make Title and Content of all Files translatable
File::add_extension("TranslatableDataObject('Title', 'Content')");
```

To edit the translations within the CMS there's a simple extension you can add to your files.

```php
File::add_extension('TranslatedFile');
```

This extension will generate a tab per language within the `Files` Section of the CMS. In addition it adds a helper method (`getUploadEditorFields`) to use when within a locale-context. You can use this to provide translated fields for editing files in a `UploadField`. Here's an example:

```php
$imageUpload = UploadField::create('Image');
$imageUpload->setFileEditFields('getUploadEditorFields');
```

### Usage and templates

Whenever you'll have to access your DataObjects, remember to use `$this->Master()->Relation()` instead of `$this->Relation()`.

`Master()` is a handy method in `translatable-dataobject/code/extensions/TranslatableUtility.php`. This extension will automatically be added to each `SiteTree` object with the installation of the translatable-dataobject module. It's a helper-method to get the master-translation of a page and can also be very useful in templates. So if you would like to output all testimonials in a template, you'd use:

```html+smarty
    <h1>$Title</h1> <!-- Page Title -->
    <p>$Content</p> <!-- Page Content -->
    <% loop Master.Testimonials %>
        <h2>$T(Title)</h2> <!-- Localized Title -->
        $T(Content) <!-- Localized Content -->
    <hr/>
    <% end_loop %>
```

Another helpful method to be used in templates is `Languages`. It will return an `ArrayList` with all information you need to build a language-navigation. Drop something like this in your template:

```html+smarty
    <ul class="langNav">
        <% loop Languages %>
        <li><a href="$Link" class="$LinkingMode" title="$Title.ATT">$Language</a></li>
        <% end_loop %>
    </ul>
```

Or you can just include a prepackaged template:

```html+smarty
<% include TdLanguageSwitcher %>
```

This will create a list of all available content-languages. The link will point to the translated page or to the home-page of that language if there's no translation in that language.

Todo:
------------

 - CMS UI improvements
 - Better integration with existing components such as the GridField
 - Better access for relations (eg. get the translated page when getting a has_one relation)

Limitations
------------

**The module currently only supports translations of DB fields that are part of the `DataObject` itself. Fields that are being added by extensions aren't translatable.**

Since this extension adds more fields to a table, it is important to note that the number of localized fields and
the number of languages could cause problems with the underlying database. Imagine a DataObject with 10 localizable fields
and a site that will be translated into 5 other languages. This would add 50 columns to the table.

According to the MySQL documentation, the hard-limit of columns for a MySQL table is at `4096`, which should be sufficient 
for most setups. Other RDBMS might have other limitations though.