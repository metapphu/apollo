<?php
namespace Metapp\Apollo\Language;

use Metapp\Apollo\Factory\Factory;

class TranslatableListener extends \Gedmo\Translatable\TranslatableListener
{
    public function __construct()
    {
        parent::__construct();
        $config = Factory::fromNames(array('route'), true);
        $lang = Language::parseLang($config);
        $this->setTranslatableLocale($lang);
        $this->setTranslationFallback(true);
        $this->setPersistDefaultLocaleTranslation(true);
        $this->setDefaultLocale($lang);
        $_SERVER["HTTP_CONTENT_LANGUAGE"] = $lang;
    }
}
