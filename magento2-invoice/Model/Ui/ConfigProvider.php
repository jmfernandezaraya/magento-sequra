<?php
/**
 * Copyright © 2017 SeQura Engineering. All rights reserved.
 */

namespace Sequra\Invoice\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * Class ConfigProvider
 */
final class ConfigProvider extends \Sequra\Core\Model\Ui\ConfigProvider
{
    const CODE = 'sequra_invoice';

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $config = parent::getConfig();
        return [
            'payment' => [
                self::CODE => [
                    'configuration' => $config['payment']['sequra_configuration'],
                    'showlogo' => $this->config->getValue('showlogo'),
                    'product' => $this->config->getValue('product')
                ]
            ]
        ];
    }
}
