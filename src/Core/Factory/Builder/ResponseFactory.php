<?php

namespace Metapp\Apollo\Core\Factory\Builder;

use Metapp\Apollo\Utility\Utils\APIResponseBuilder;
use Psr\Http\Message\ResponseInterface;

class ResponseFactory
{
    /**
     * @param ResponseInterface $response
     * @param APIResponseBuilder $builder
     * @param string $message
     * @return ResponseInterface
     */
    public function badRequest(ResponseInterface $response, APIResponseBuilder $builder, string $message): ResponseInterface
    {
        $builder->setMessage($message, 400);
        $response->getBody()->write($builder->build());
        return $response->withStatus($builder->getStatus());
    }
}