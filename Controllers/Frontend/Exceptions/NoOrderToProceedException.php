<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Controllers\Frontend\Exceptions;

use Exception;

class NoOrderToProceedException extends Exception
{
    public function __construct()
    {
        parent::__construct('No order to proceed');
    }
}
