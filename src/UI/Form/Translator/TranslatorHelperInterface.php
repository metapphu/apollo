<?php
namespace Metapp\Apollo\UI\Form\Translator;

interface TranslatorHelperInterface
{
    /**
     * @param $key
     * @return string
     */
    public function trans($key);
}