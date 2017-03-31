<?php
/**
 * Tweakwise & Emico (https://www.tweakwise.com/ & https://www.emico.nl/) - All Rights Reserved
 *
 * @copyright Copyright (c) 2017-2017 Tweakwise.com B.V. (https://www.tweakwise.com)
 * @license   Proprietary and confidential, Unauthorized copying of this file, via any medium is strictly prohibited
 */

namespace Emico\Tweakwise\Model;

use Emico\Tweakwise\Exception\ApiException;
use Emico\Tweakwise\Model\Client\Request;
use Emico\Tweakwise\Model\Client\Response;
use Emico\Tweakwise\Model\Client\ResponseFactory;
use Emico\TweakwiseExport\Model\Logger;
use Magento\Framework\Profiler;
use SimpleXMLElement;
use Zend\Http\Client as HttpClient;
use Zend\Http\Exception\ExceptionInterface as HttpException;

class Client
{
    /**
     * Request path constants
     */
    const REQUEST_PATH_NAVIGATION = 'navigation';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Logger
     */
    protected $log;

    /**
     * @var ResponseFactory
     */
    protected $requestFactory;

    /**
     * Client constructor.
     *
     * @param Config $config
     * @param Logger $log
     * @param ResponseFactory $responseFactory
     */
    public function __construct(Config $config, Logger $log, ResponseFactory $responseFactory)
    {
        $this->config = $config;
        $this->log = $log;
        $this->responseFactory = $responseFactory;
    }

    /**
     * @param string $path
     * @param array|null $parameters
     * @return HttpClient
     */
    protected function createClient($path, array $parameters = null)
    {
        $url = sprintf('%s/%s/%s', rtrim($this->config->getGeneralServerUrl(), '/'), trim($path, '/'), $this->config->getGeneralAuthenticationKey());

        $client = new HttpClient();
        $client->setOptions(['timeout' => $this->config->getTimeout()]);
        $client->setUri($url);
        $client->getUri()->setQuery($parameters);

        return $client;
    }

    /**
     * @param Request $request
     * @return Response
     */
    protected function doRequest(Request $request)
    {
        $client = $this->createClient($request->getPath(), $request->getParameters());

        $start = microtime(true);
        try {
            $response = $client->send();
        } catch (HttpException $e) {
            throw new ApiException($e->getMessage(), $e->getCode(), $e);
        } finally {
            $time = microtime(true) - $start;
            $this->log->debug(sprintf('[Request][%.5f] %s', $time, (string) $client->getUri()));
        }

        if ($response->getStatusCode() != 200) {
            throw new ApiException('Invalid response received by Tweakwise server, response code is not 200.', $response->getStatusCode());
        }

        $xmlPreviousErrors = libxml_use_internal_errors(true);
        try {
            $xmlElement = simplexml_load_string($response->getBody(), SimpleXMLElement::class, LIBXML_NOCDATA);
            if ($xmlElement === false) {
                $errors = libxml_get_errors();
                throw new ApiException(sprintf('Invalid response received by Tweakwise server, xml load fails. %s', join(PHP_EOL, $errors)));
            }
        } finally {
            libxml_use_internal_errors($xmlPreviousErrors);
        }

        $result = $this->xmlToArray($xmlElement);
        return $this->responseFactory->create($request, $result);
    }

    /**
     * @param SimpleXMLElement $element
     * @return array
     */
    protected function xmlToArray(SimpleXMLElement $element)
    {
        $result = [];
        foreach ($element->attributes() as $attribute => $value) {
            $result['@' . $attribute] = (string) $value;
        }

        /** @var SimpleXMLElement $node */
        foreach ((array) $element as $index => $node) {
            if ($node instanceof SimpleXMLElement) {
                $value = $this->xmlToArray($node);
            } elseif (is_array($node)) {
                $value = [];
                foreach ($node as $element) {
                    $value[] = $this->xmlToArray($element);
                }
            } else {
                $value = (string) $node;
            }

            $result[$index] = $value;
        }

        return $result;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function request(Request $request)
    {
        Profiler::start('tweakwise::request::' . $request->getPath());
        try {
            return $this->doRequest($request);
        } catch (ApiException $e) {
            $this->log->throwException($e);
        } finally {
            Profiler::stop('tweakwise::request::' . $request->getPath());
        }
    }
}