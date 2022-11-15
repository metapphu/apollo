<?php

namespace Metapp\Apollo\Route;

use Cherif\InertiaPsr15\Middleware\InertiaMiddleware;
use FastRoute\DataGenerator;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedDataGenerator;
use FastRoute\RouteCollector;
use FastRoute\RouteParser;
use FastRoute\RouteParser\Std as StdRouteParser;
use League\Container\Container;
use League\Route\ContainerAwareInterface;
use League\Route\ContainerAwareTrait;
use Metapp\Apollo\Auth\Auth;
use Metapp\Apollo\Config\Config;
use Metapp\Apollo\Config\ConfigurableFactoryInterface;
use Metapp\Apollo\Config\ConfigurableFactoryTrait;
use GuzzleHttp\Psr7\Response;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Metapp\Apollo\Logger\Interfaces\LoggerHelperInterface;
use Metapp\Apollo\Logger\Traits\LoggerHelperTrait;

class Router extends \League\Route\Router implements LoggerHelperInterface, ConfigurableFactoryInterface, ContainerAwareInterface
{
    use LoggerHelperTrait;
    use ConfigurableFactoryTrait;
    use ContainerAwareTrait;

    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var array
     */
    protected $customPatterns = array();

    /**
     * @var array
     */
    private $valid_ContentTypes = array(
        'application/x-www-form-urlencoded',
        'application/json',
        'application/xml',
    );

    /**
     * @var RouteValidatorInterface
     */
    private $validator;

    /**
     * Constructor.
     *
     * @param \Psr\Container\ContainerInterface $container
     */
    public function __construct(ContainerInterface $container = null,
                                RouteParser        $parser    = null,
                                DataGenerator      $generator = null) {
        $this->container = ($container instanceof ContainerInterface) ? $container : new Container;
        // build parent route collector
        $parser    = ($parser instanceof RouteParser) ? $parser : new RouteParser\Std();
        $generator = ($generator instanceof DataGenerator) ? $generator : new DataGenerator\GroupCountBased();
        parent::__construct(new RouteCollector(
            $parser,
            $generator
        ));
       // $this->lazyMiddleware(\Cherif\InertiaPsr15\Middleware\InertiaMiddleware::class);
    }

    /**
     * @return Router
     */
    public function buildRoutes()
    {
        if (!empty($this->customPatterns)) {
            foreach ($this->customPatterns as $name => $pattern) {
                $this->addPatternMatcher($name, $pattern);
            }
        }

        $cfg = $this->config->get();
        if ($this->config->has('strategy')) {
            $strategyClass = $this->config->get('strategy');
            if ($this->container->has($strategyClass)) {
                $str = $this->container->get($strategyClass);
                $str->setContainer($this->container);
                $this->setStrategy($str);
            }
        }
        if ($this->config->has('paths')) {
            $this->addPaths($this->config->get('paths'), $cfg);
        }

        return $this;
    }

    /**
     * @return ResponseInterface
     */
    public function go()
    {
        $config = $this->container->get(Config::class);
        /** @var ServerRequestInterface $serverRequestInterface */
        $serverRequestInterface = $this->container->get(ServerRequestInterface::class);
        $newPath = $serverRequestInterface->getUri()->getPath();
        foreach (array_diff(scandir($config->get(array('route','translator','path'),'')),array('.', '..')) as $lang) {
            $cleanLang = str_replace(".php","",$lang);
            if($newPath == '/'.$cleanLang){
                $newPath = '/';
            }else{
                $newPath = str_replace('/'.$cleanLang.'/','/',$newPath);
            }
        }
        $newInterface = $serverRequestInterface->withUri($serverRequestInterface->getUri()->withPath($newPath));
        return parent::dispatch($newInterface, $this->container->get(Response::class));
    }

    /**
     * @return ServerRequestInterface|null
     */
    public function getRequest()
    {
        return $this->container->get(ServerRequestInterface::class);
    }

    /**
     * @param RouteValidatorInterface $validator
     * @return Router
     */
    public function setValidator(RouteValidatorInterface $validator)
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * @param $paths
     * @param $data
     */
    public static function mergePaths(&$paths, $data)
    {
        foreach ($data as $path => $options) {
            if (empty($paths[$path])) {
                $paths[$path] = $options;
            } else {
                if (!empty($options['methods'])) {
                    foreach ($options['methods'] as $method => $params) {
                        $paths[$path]['methods'][$method] = $params;
                    }
                }
                if (!empty($options['paths'])) {
                    foreach ($options['paths'] as $sub => $sub_data) {
                        if (!isset($paths[$path]['paths'][$sub])) {
                            $paths[$path]['paths'][$sub] = array();
                        }
                        self::mergePaths($paths[$path]['paths'], array($sub => $sub_data));
                    }
                }
            }
        }
    }

    /**
     * @param array $paths
     * @param array $cfg
     * @param string $pre
     */
    private function addPaths(array $paths, array $cfg, $pre = '')
    {
        foreach ($paths as $path => $data) {
            if (!empty($data['methods'])) {
                foreach ($data['methods'] as $method => $options) {
                    $map = $this->map($method, rtrim($pre, '/') . '/' . trim($path, '/'), $options['callable']);

                    if (!empty($options['strategy']) && is_callable(array($options['strategy'], 'getExceptionDecorator'))) {
                        $map->setStrategy($this->container->get($options['strategy']));
                    }
                    if (!empty($data['strategy']) && is_callable(array($data['strategy'], 'getExceptionDecorator'))) {
                        $map->setStrategy($this->container->get($data['strategy']));
                    }
                    if (!empty($data['auth_method'])) {
                        $options["auth_method"] = $data["auth_method"];
                    }
                    if (!empty($options['name'])) {
                        $map->setName($options['name']);
                    }
                    $requires = $this->requires(
                        $cfg,
                        $options,
                        rtrim($pre, '/') . '/' . trim($path, '/'),
                        array(
                            'required_fields' => array(),
                            'required_headers' => array(),
                            'required_ContentType' => '',
                            'require_auth' => false,
                            'auth_method' => Auth::Session,
                            'require_permissions' => array(),
                            'required_permission_groups' => array(),
                        )
                    );
                    $options['valid_ContentTypes'] = $this->valid_ContentTypes;
                    $options['method'] = $method;

                    if ($this->validator instanceof RouteValidatorInterface) {
                        $validator = $this->validator;
                        $map = $validator->validate($map, $requires, $options, $this->container);

                    }
                }
            }
            if (!empty($data['paths'])) {
                $this->addPaths($data['paths'], $cfg, rtrim($pre, '/') . '/' . trim($path, '/'));
            }
        }
    }

    /**
     * @param array $cfg
     * @param array $options
     * @param string $pre
     * @param $requires
     * @return array
     */
    private function requires(array $cfg, array $options, $pre, $requires)
    {
        $keys = array_keys($requires);

        foreach ($keys as $key) {
            if (isset($cfg[$key])) {
                $requires[$key] = $cfg[$key];
            }
        }

        $paths = explode('/', $pre);
        $path = '/' . trim(array_shift($paths), '/');
        $cfg = $cfg['paths'][$path];
        foreach ($keys as $key) {
            if (isset($cfg[$key])) {
                $requires[$key] = $cfg[$key];
            }
        }

        while (!empty($paths)) {
            $path = trim(array_shift($paths), '/');
            if ($path) {
                $path = '/' . $path;
                $cfg = $cfg['paths'][$path];
                foreach ($keys as $key) {
                    if (isset($cfg[$key])) {
                        $requires[$key] = $cfg[$key];
                    }
                }
            }
        }

        foreach ($keys as $key) {
            if (isset($options[$key])) {
                $requires[$key] = $options[$key];
            }
        }

        return $requires;
    }

    public function getBasepath()
    {
        return $this->config->get('basepath', '/');
    }

    /**
     * @param $url
     * @return string
     */
    public function getRealUrl($url)
    {
        $basepath = rtrim($this->getBasepath(), '/');
        return implode('/', array($basepath, ltrim($url, '/')));
    }

}
