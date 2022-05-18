<?php
namespace Metapp\Apollo\Form\Translator;

interface TranslatorLoaderInterface extends TranslatorAwareInterface
{
    /**
     * @param string|null $textDomain
     * @noinspection PhpUnused
     */
    public function autoLoadTranslator($textDomain = null);
}