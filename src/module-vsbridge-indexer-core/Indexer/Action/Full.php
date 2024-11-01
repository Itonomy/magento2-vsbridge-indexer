<?php

namespace Divante\VsbridgeIndexerCore\Indexer\Action;

use Divante\VsbridgeIndexerCore\Indexer\RebuildActionPool;
use Divante\VsbridgeIndexerCore\Indexer\StoreManager;
use Divante\VsbridgeIndexerCore\Model\ElasticsearchResolverInterface;
use Divante\VsbridgeIndexerCore\Indexer\GenericIndexerHandlerFactory;

/**
 * Full reindex action
 */
class Full extends AbstractAction
{
    /**
     * @var ElasticsearchResolverInterface
     */
    private $esVersionResolver;

    /**
     * Full constructor.
     * @param ElasticsearchResolverInterface $esVersionResolver
     * @param RebuildActionPool $actionPool
     * @param GenericIndexerHandlerFactory $indexerHandlerFactory
     * @param StoreManager $storeManager
     * @param string $typeName
     */
    public function __construct(
        ElasticsearchResolverInterface $esVersionResolver,
        RebuildActionPool $actionPool,
        GenericIndexerHandlerFactory $indexerHandlerFactory,
        StoreManager $storeManager,
        string $typeName
    ) {
        parent::__construct($actionPool, $indexerHandlerFactory, $storeManager, $typeName);

        $this->esVersionResolver = $esVersionResolver;
    }

    /**
     * Execute full reindex
     *
     * @param array $ids
     *
     * @return void
     */
    public function execute(array $ids)
    {
        $esVersion = $this->esVersionResolver->getVersion();
        $stores = $this->getStores();

        if ($esVersion === ElasticsearchResolverInterface::DEFAULT_ES_VERSION) {
            foreach ($stores as $store) {
                $this->getIndexerHandler()->saveIndex($this->rebuild((int) $store->getId(), []), $store);
                $this->getIndexerHandler()->cleanUpByTransactionKey($store);
            }
        } else {
            foreach ($stores as $store) {
                $this->getIndexerHandler()->createIndex($store);
                $this->getIndexerHandler()->saveIndex($this->rebuild((int) $store->getId(), []), $store);
            }
        }
    }
}
