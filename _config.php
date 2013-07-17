<?php
/**
 * Extension that adds translations vertically (eg. adds more fields to a DB table instead of 
 * creating new tables).
 * 
 * Well suited to add translations to DataObjects.
 * 
 * This is meant as an *addition* to the SilverStripe translatable module. Hence, the 
 * translatable module is a requirement: https://github.com/silverstripe/silverstripe-translatable
 */

define('TD_COMPAT_SS30X', 300);
define('TD_COMPAT_SS31X', 310);

if(!defined('TRANSLATABLE_COLUMN_SEPARATOR')){
	define('TRANSLATABLE_COLUMN_SEPARATOR', '__');
}

$method = new ReflectionMethod('Object', 'has_extension');
$num = $method->getNumberOfParameters();

if($num < 2){
	define('TD_SS_COMPATIBILITY', TD_COMPAT_SS31X);
} else {
	define('TD_SS_COMPATIBILITY', TD_COMPAT_SS30X);
}

Object::add_extension('SiteTree', 'TranslatableUtility');
