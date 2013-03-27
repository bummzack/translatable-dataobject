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

if(!defined('TRANSLATABLE_COLUMN_SEPARATOR')){
	define('TRANSLATABLE_COLUMN_SEPARATOR', '__');
}

SiteTree::add_extension('TranslatableUtility');
