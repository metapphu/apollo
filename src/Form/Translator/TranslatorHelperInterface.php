<?php
namespace Metapp\Apollo\Form\Translator;

interface TranslatorHelperInterface
{
    /**
     * @param $key
     * @return string
     */
    public function trans($key);
}