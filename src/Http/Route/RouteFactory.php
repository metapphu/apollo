<?php

namespace Metapp\Apollo\Http\Route;


use Exception;
use FastRoute\DataGenerator;
use FastRoute\RouteParser;
use GuzzleHttp\Psr7\Response;
use League\Container\Container;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use Metapp\Apollo\Core\Config\ConfigurableFactoryInterface;
use Metapp\Apollo\Core\Config\ConfigurableFactoryTrait;
use Metapp\Apollo\Core\Factory\InvokableFactoryInterface;
use Metapp\Apollo\UI\Html\Html;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

class RouteFactory implements InvokableFactoryInterface, ConfigurableFactoryInterface, ContainerAwareInterface
{
    use ConfigurableFactoryTrait;
    use ContainerAwareTrait;

    /**
     * @return Router
     * @throws Exception
     */
    public function __invoke()
    {
        if (null == $this->config) {
            throw new Exception(__CLASS__ . " can't work without configuration");
        }

        $request = $this->container->get(ServerRequestInterface::class);
        try {
            Html::parseRequest($request);
        } catch (Exception $e) {
            $response = new Response(400, array(), strtok($e->getMessage(), "\n"));
            try {
                /** @var Environment $twig */
                $twig = $this->container->get(Environment::class);
            } catch (Exception $e) {
            } finally {
                if ($twig instanceof Environment) {
                    $params = array(
                        'title' => $response->getStatusCode(),
                        'block' => array(
                            'title' => $response->getReasonPhrase(),
                        ),
                    );
                    $response->getBody()->write($twig->render('errors.html.twig', $params));
                }
            }
            die(Html::response($response));
        }

        $container = new Container();
        $container->addShared(ServerRequestInterface::class, $request);
        $container->delegate($this->container);

        $parser = $this->container->has(RouteParser::class) ? $this->container->get(RouteParser::class) : null;
        $generator = $this->container->has(DataGenerator::class) ? $this->container->get(DataGenerator::class) : null;
        $router = new Router($container, $parser, $generator);

        $router->configure($this->config);


        if ($validatorClass = $this->config->get('validator')) {
            $router->setValidator($this->container->get($validatorClass));
        }

        $patternMatchers = $this->config->get('patternMatchers', array());
        if (!empty($patternMatchers)) {
            foreach ($patternMatchers as $alias => $regex) {
                $router->addPatternMatcher($alias, $regex);
            }
        }

        return $router;
    }
}
