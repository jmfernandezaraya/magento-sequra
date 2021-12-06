<?php
/**
 * Copyright © 2017 SeQura Engineering. All rights reserved.
 */

namespace Sequra\Core\Model\Api;

class BuilderFactory
{
    /**
     * Object Manager instance
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager = null;

    /**
     * @var array
     */
    protected $mapping = [];

    /**
     * Factory constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param array $mapping
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager, array $mapping = [])
    {
        $this->_objectManager = $objectManager;
        $this->mapping = $mapping;
    }

    /**
     * Create class instance with specified parameters
     *
     * @param string $type
     * @param string $merchant_ref
     *
     * @return \Sequra\Core\Model\Api\BuilderInterface
     */
    public function create($type = 'order')
    {
        switch($type){
            case 'report':
                return $this->_objectManager->create('Sequra\Core\Model\Api\Builder\Report');
            case 'order-update':
                return $this->_objectManager->create('Sequra\Core\Model\Api\Builder\OrderUpdate');
            default:
                return $this->_objectManager->create('Sequra\Core\Model\Api\Builder\Order');
        }

    }
}
