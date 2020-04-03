<?php

namespace Catgento\Redsys\Controller\OkResult;

use Magento\Framework\App\Action\Action;

/**
 * Class Index
 * @package Catgento\Redsys\Controller\OkResult
 */
class Index extends Action
{

    public function execute()
    {
        $this->_redirect('checkout/onepage/success');
    }

}