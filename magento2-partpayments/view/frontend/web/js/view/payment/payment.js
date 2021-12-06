/**
 * Copyright © 2017 SeQura Engineering. All rights reserved.
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'sequra_partpayments',
                component: 'Sequra_Partpayments/js/view/payment/method-renderer/payment'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
