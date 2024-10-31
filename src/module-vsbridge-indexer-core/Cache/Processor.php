<?php
/**
 * @package  Divante\VsbridgeIndexerCore
 * @author Agata Firlejczyk <afirlejczyk@divante.pl>
 * @copyright 2019 Divante Sp. z o.o.
 * @license See LICENSE_DIVANTE.txt for license details.
 */

namespace Divante\VsbridgeIndexerCore\Cache;

use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;
use Laminas\Http\Request;

/**
 * Class Processor
 */
class Processor
{
    /**
     * Mapping elastic type to cache tag used by vsf
     * @var array
     */
    private $defaultCacheTags = [
        'category' => 'C',
        'product' => 'P',
    ];

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $cacheTags;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * Processor constructor.
     *
     * @param CurlFactory $curlFactory
     * @param ConfigInterface $config
     * @param EventManager $manager
     * @param LoggerInterface $logger
     */
    public function __construct(
        CurlFactory $curlFactory,
        ConfigInterface $config,
        EventManager $manager,
        LoggerInterface $logger
    ) {
        $this->eventManager = $manager;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * @param int $storeId
     * @param string $dataType
     * @param array $entityIds
     *
     * @return $this
     */
    public function cleanCacheByDocIds($storeId, $dataType, array $entityIds)
    {
        if ($this->config->clearCache($storeId)) {
            if (!empty($entityIds)) {
                $this->cleanCacheInBatches($storeId, $dataType, $entityIds);
            } else {
                $cacheTags = $this->getCacheTags();

                if (isset($cacheTags[$dataType])) {
                    $this->cleanCacheByTags($storeId, [$dataType]);
                }
            }
        }

        return $this;
    }

    /**
     * @param int $storeId
     * @param string $dataType
     * @param array $entityIds
     */
    public function cleanCacheInBatches(int $storeId, string $dataType, array $entityIds)
    {
        $batchSize = $this->getInvalidateEntitiesBatchSize($storeId);
        $batches = [$entityIds];

        if ($batchSize) {
            $batches = array_chunk($entityIds, $batchSize);
        }

        foreach ($batches as $batch) {
            $this->logger->debug('BATCHES ' . implode(', ', $batch));
            $cacheInvalidateUrl = $this->getCacheInvalidateUrl($storeId, $dataType, $entityIds);

            try {
                $this->call($storeId, $cacheInvalidateUrl);
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    /**
     * @param int $storeId
     * @param array $tags
     */
    public function cleanCacheByTags($storeId, array $tags)
    {
        $storeId = (int) $storeId;

        if ($this->config->clearCache($storeId)) {
            $cacheTags = implode(',', $tags);
            $cacheInvalidateUrl = $this->getInvalidateCacheUrl($storeId) . $cacheTags;

            try {
                $this->call($storeId, $cacheInvalidateUrl);
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    /**
     * @param int $storeId
     *
     * @return int
     */
    private function getInvalidateEntitiesBatchSize(int $storeId)
    {
        return $this->config->getInvalidateEntitiesBatchSize($storeId);
    }

    /**
     * @param string $storeId
     * @param string $uri
     */
    private function call($storeId, $uri)
    {
        $config = $this->config->getConnectionOptions($storeId);
        /** @var \Magento\Framework\HTTP\Adapter\Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setConfig($config);
        $curl->write(Request::METHOD_GET, $uri, '1.0');
        $response = $curl->read();

        if ($response !== false && !empty($response)) {
            $httpCode = $this->extractCode($response);

            if ($httpCode !== 200) {
                $response = $this->extractBody($response);
                $this->logger->error($response);
            }
        } else {
            $this->logger->error('Problem with clearing VSF cache.');
        }
    }

    /**
     * Extract the response code from a response string
     *
     * @param string $response_str
     * @return int
     */
    private function extractCode($response_str)
    {
        preg_match("|^HTTP/[\d\.x]+ (\d+)|", $response_str, $m);

        if (isset($m[1])) {
            return (int) $m[1];
        } else {
            return false;
        }
    }

    /**
     * Extract the body from a response string
     *
     * @param string $response_str
     * @return string
     */
    private function extractBody($response_str)
    {
        $parts = preg_split('|(?:\r\n){2}|m', $response_str, 2);
        if (isset($parts[1])) {
            return $parts[1];
        }
        return '';
    }

    /**
     * @param int $storeId
     * @param string $type
     * @param array  $ids
     *
     * @return string
     */
    private function getCacheInvalidateUrl($storeId, $type, array $ids)
    {
        $fullUrl = $this->getInvalidateCacheUrl($storeId);
        $params = $this->prepareTagsByDocIds($type, $ids);
        $fullUrl .= $params;

        return $fullUrl;
    }

    /**
     * @return string
     */
    private function getInvalidateCacheUrl($storeId)
    {
        $url = $this->config->getVsfBaseUrl($storeId);
        $url .= sprintf('invalidate?key=%s&tag=', $this->config->getInvalidateCacheKey($storeId));

        return $url;
    }

    /**
     * @param string $type
     * @param array $ids
     *
     * @return string
     */
    public function prepareTagsByDocIds($type, array $ids)
    {
        $params = '';
        $cacheTags = $this->getCacheTags();

        if (isset($cacheTags[$type])) {
            $cacheTag = $cacheTags[$type];
            $count = count($ids);

            foreach ($ids as $key => $id) {
                $params .= $cacheTag . $id;

                if ($key !== ($count - 1)) {
                    $params .= ',';
                }
            }
        }

        return $params;
    }

    /**
     * @return array
     */
    public function getCacheTags()
    {
        if (null === $this->cacheTags) {
            $tagsDataObject = new \Magento\Framework\DataObject();
            $tagsDataObject->setData('items', $this->defaultCacheTags);
            $this->eventManager->dispatch(
                'vsf_prepare_cache_tags',
                ['cache_tags' => $tagsDataObject]
            );
            $this->cacheTags = $tagsDataObject->getData('items');
        }

        return $this->cacheTags;
    }
}
