<?php
class TranslatableUtility extends DataExtension
{
	/**
	 * Get the translation master of this page
	 * @return SiteTree
	 */
	public function Master(){
		if(Translatable::get_current_locale() != Translatable::default_locale()){
			if($master = $this->owner->getTranslation(Translatable::default_locale())){
				return $master;
			}
		}
	
		return $this->owner;
	}

	/**
	 * Helper method to get content languages from the live DB table.
	 * Most of the code is borrowed from the Translatable::get_live_content_languages method.
	 * This method operates on "SiteTree" and makes a distinction between Live and Stage.
	 * @return array
	 */
	static function get_content_languages() {
		$table = Versioned::current_stage() == 'Live' ? 'SiteTree_Live' : 'SiteTree';
		$query = new SQLQuery("Distinct \"Locale\"","\"$table\"", '', '', '"Locale"');
		$dbLangs = $query->execute()->column();
		$langlist = array_merge((array)Translatable::default_locale(), (array)$dbLangs);
		$returnMap = array();
		$allCodes = array_merge(
			Config::inst()->get('i18n', 'all_locales'),
			Config::inst()->get('i18n', 'common_locales')
		);
		foreach ($langlist as $langCode) {
			if($langCode && isset($allCodes[$langCode])) {
				if(is_array($allCodes[$langCode])) {
					$returnMap[$langCode] = $allCodes[$langCode]['name'];
				} else {
					$returnMap[$langCode] = $allCodes[$langCode];
				}
			}
		}
		return $returnMap;
	}


	/**
	 * Get a set of content languages (for quick language navigation)
	 * @example
	 * <code>
	 * <!-- in your template -->
	 * <ul class="langNav">
	 * 		<% loop Languages %>
	 * 		<li><a href="$Link" class="$LinkingMode" title="$Title.ATT">$Language</a></li>
	 * 		<% end_loop %>
	 * </ul>
	 * </code>
	 * 
	 * @return ArrayList|null
	 */
	public function Languages(){
		$locales = TranslatableUtility::get_content_languages();

		// there's no need to show a navigation when there's less than 2 languages. So return null
		if(!$locales || count($locales) < 2){
			return null;
		}
		
		$currentLocale = Translatable::get_current_locale();
		$homeTranslated = null;
		if($home = SiteTree::get_by_link('home')){
			$homeTranslated = $home->getTranslation($currentLocale);
		}
		$langSet = ArrayList::create();
		foreach($locales as $locale => $name){
			Translatable::set_current_locale($locale);
			$translation = $this->owner->hasTranslation($locale) ? $this->owner->getTranslation($locale) : null;
	
			$langSet->push(new ArrayData(array(
				// the locale (eg. en_US)
				'Locale' => $locale,
				// locale conforming to rfc 1766
				'RFC1766' => i18n::convert_rfc1766($locale),
				// the language 2 letter code (eg. EN)
				'Language' => DBField::create_field('Varchar', 
						strtoupper(i18n::get_lang_from_locale($locale))),
				// the language as written in its native language
				'Title'	=> DBField::create_field('Varchar', ucfirst(html_entity_decode(
							i18n::get_language_name(i18n::get_lang_from_locale($locale), true),
							ENT_NOQUOTES, 'UTF-8'))),
				// linking mode (useful for css class)
				'LinkingMode' => $currentLocale == $locale ? 'current' : 'link',
				// link to the translation or the home-page if no translation exists for the current page
				'Link' => $translation  ? $translation->AbsoluteLink() : ($homeTranslated ? $homeTranslated->Link() : '')
			)));
	
		}
	
		Translatable::set_current_locale($currentLocale);
		i18n::set_locale($currentLocale);
		return $langSet;
	}
	
}
