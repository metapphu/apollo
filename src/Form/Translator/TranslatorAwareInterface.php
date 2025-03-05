<?php
namespace Metapp\Apollo\Form\Translator;

use Laminas\Translator\TranslatorInterface;

interface TranslatorAwareInterface
{
    /**
     * @return TranslatorInterface|null
     */
    public function getTranslator();

    /**
     * @param TranslatorInterface $translator
     */
    public function setTranslator(TranslatorInterface $translator);
}