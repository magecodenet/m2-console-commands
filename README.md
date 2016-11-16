#Magento 2 - M2 Extended Console Commands

Install with Composer

    composer require magecodenet/m2-console-commands

Refresh Statistic

    php bin/magento magecode:statistic:refresh [date]

Update customer addresses by sales order addresses

    php bin/magento magecode:customers:syncByOrders [STORE-ID] [PAGE-START] [PAGE-END] [ADDRESSES-LIMIT]
