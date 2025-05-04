<?php
namespace Metapp\Apollo\UI\Twig;

use Metapp\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use Metapp\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Twig\Environment;
use Twig\Error\Error;

class Twig extends Environment implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @param $name
     * @param array $context
     * @return string
     */
    public function render($name, array $context = array()) :string
    {
        try {
            $page = parent::render($name, $context);
        } catch (Error $e) {
            $this->error('Twig_Error', (array)$e);
            $page = '';
        }
        return $page;
    }
}
