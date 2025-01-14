<?php
namespace Metapp\Apollo\Doctrine;

use Exception;
use Metapp\Apollo\Config\Config;
use Metapp\Apollo\Config\ConfigurableFactoryInterface;
use Metapp\Apollo\Config\ConfigurableFactoryTrait;
use Metapp\Apollo\Factory\InvokableFactoryInterface;

class TablePrefixFactory implements InvokableFactoryInterface, ConfigurableFactoryInterface
{
    use ConfigurableFactoryTrait;

    /**
     * @return TablePrefix
     * @throws Exception
     */
    public function __invoke()
    {
        if (!$this->config instanceof Config) {
            throw new Exception(__CLASS__ . " can't work without configuration");
        }

        return new TablePrefix(
            $this->config->get('prefix', ''),
            $this->config->get('prefix_namespaces', array())
        );
    }
}
