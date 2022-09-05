<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Tests\Functional\Controller\Frontend;

use Enlight_Controller_Request_RequestTestCase;
use Enlight_Controller_Response_ResponseHttp;
use Generator;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware_Controllers_Frontend_PaypalUnifiedV2;
use SwagPaymentPayPalUnified\Components\Services\SettingsService;
use SwagPaymentPayPalUnified\Components\Services\Validation\SimpleBasketValidator;
use SwagPaymentPayPalUnified\PayPalBundle\Components\SettingsServiceInterface;
use SwagPaymentPayPalUnified\PayPalBundle\Components\SettingsTable;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit\Amount;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit\Payments;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit\Payments\Capture;
use SwagPaymentPayPalUnified\PayPalBundle\V2\PaymentIntentV2;
use SwagPaymentPayPalUnified\PayPalBundle\V2\PaymentStatusV2;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Resource\OrderResource;
use SwagPaymentPayPalUnified\Tests\Functional\ContainerTrait;
use SwagPaymentPayPalUnified\Tests\Functional\SettingsHelperTrait;
use SwagPaymentPayPalUnified\Tests\Functional\ShopRegistrationTrait;
use SwagPaymentPayPalUnified\Tests\Unit\PaypalPaymentControllerTestCase;

class PaypalUnifiedReturnActionNotInContextTest extends PaypalPaymentControllerTestCase
{
    use ContainerTrait;
    use SettingsHelperTrait;
    use ShopRegistrationTrait;

    /**
     * @after
     *
     * @return void
     */
    public function reset()
    {
        $this->getContainer()->get('session')->offsetUnset('sOrderVariables');
        $this->getContainer()->get('dbal_connection')->exec('DELETE FROM swag_payment_paypal_unified_settings_general WHERE true;');
    }

    /**
     * @dataProvider returnActionShouldRedirectWithTokenRequestParameterTestDataProvider
     *
     * @param array<string,string> $requestParameter
     * @param int                  $useInContext
     * @param string               $expectedResult
     *
     * @return void
     */
    public function testReturnActionShouldRedirectWithTokenRequestParameter(array $requestParameter, $useInContext, $expectedResult)
    {
        $sBasket = require __DIR__ . '/_fixtures/getBasket_result.php';
        $sUserData = require __DIR__ . '/_fixtures/getUser_result.php';

        $this->getContainer()->get('session')->offsetSet('sOrderVariables', ['sBasket' => $sBasket, 'sUserData' => $sUserData]);

        $controller = $this->getController(
            Shopware_Controllers_Frontend_PaypalUnifiedV2::class,
            [
                self::SERVICE_ORDER_RESOURCE => $this->createOrderResource(),
                self::SERVICE_SIMPLE_BASKET_VALIDATOR => $this->createSimpleBasketValidator(),
                self::SERVICE_SETTINGS_SERVICE => $this->createSettingService($useInContext),
            ],
            $this->createRequest($requestParameter),
            new Enlight_Controller_Response_ResponseHttp()
        );

        $controller->returnAction();

        $result = $this->getLocation($controller);

        static::assertTrue(\is_array($result));

        static::assertStringEndsWith($expectedResult, $result['value']);
    }

    /**
     * @return Generator<array<int,mixed>>
     */
    public function returnActionShouldRedirectWithTokenRequestParameterTestDataProvider()
    {
        yield 'request contains token' => [
            ['key' => 'token', 'value' => '08154711'],
            0,
            'checkout/finish/sUniqueID/08154711',
        ];

        yield 'negative test -> request contains paypalOrderId' => [
            ['key' => 'paypalOrderId', 'value' => '42007579'],
            1,
            'checkout/finish/sUniqueID/42007579',
        ];
    }

    /**
     * @return void
     */
    public function testReturnActionWithoutRequestParameter()
    {
        $this->insertGeneralSettingsFromArray(['active' => 1, 'use_in_context' => 0]);

        $orderResource = $this->createMock(OrderResource::class);
        $orderResource->expects(static::once())->method('get')->willReturn(null);

        $controller = $this->getController(
            Shopware_Controllers_Frontend_PaypalUnifiedV2::class,
            [
                self::SERVICE_ORDER_RESOURCE => $orderResource,
            ],
            new Enlight_Controller_Request_RequestTestCase(),
            new Enlight_Controller_Response_ResponseHttp()
        );

        $controller->returnAction();

        $result = $this->getLocation($controller);

        static::assertFalse($result);
    }

    /**
     * @return OrderResource&MockObject
     */
    private function createOrderResource()
    {
        $payPalOrder = $this->createPayPalOrder();
        $orderResource = $this->createMock(OrderResource::class);
        $orderResource->expects(static::once())->method('get')->willReturn($payPalOrder);
        $orderResource->expects(static::once())->method('capture')->willReturn($payPalOrder);

        return $orderResource;
    }

    /**
     * @param array<string,string> $requestParameter
     *
     * @return Enlight_Controller_Request_RequestTestCase
     */
    private function createRequest(array $requestParameter)
    {
        $request = new Enlight_Controller_Request_RequestTestCase();
        $request->setParam($requestParameter['key'], $requestParameter['value']);

        return $request;
    }

    /**
     * @return SimpleBasketValidator&MockObject
     */
    private function createSimpleBasketValidator()
    {
        $simpleBasketValidator = $this->createMock(SimpleBasketValidator::class);
        $simpleBasketValidator->expects(static::once())->method('validate')->willReturn(true);

        return $simpleBasketValidator;
    }

    /**
     * @param int $returnValue
     *
     * @return SettingsService&MockObject
     */
    private function createSettingService($returnValue)
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('get')->willReturnMap([
            [SettingsServiceInterface::SETTING_GENERAL_USE_IN_CONTEXT, SettingsTable::GENERAL, $returnValue],
            [SettingsServiceInterface::SETTING_GENERAL_SEND_ORDER_NUMBER, SettingsTable::GENERAL, 0],
        ]);

        return $settingsService;
    }

    /**
     * @return Order
     */
    private function createPayPalOrder()
    {
        $capture = new Capture();
        $capture->setStatus(PaymentStatusV2::ORDER_CAPTURE_COMPLETED);

        $payments = new Payments();
        $payments->setCaptures([$capture]);

        $amount = new Amount();
        $amount->setValue('100');

        $purchaseUnit = new PurchaseUnit();
        $purchaseUnit->setPayments($payments);
        $purchaseUnit->setAmount($amount);

        $order = new Order();
        $order->setId('anyId');
        $order->setIntent(PaymentIntentV2::CAPTURE);
        $order->setPurchaseUnits([$purchaseUnit]);

        return $order;
    }

    /**
     * @return false|array<string,mixed>
     */
    private function getLocation(Shopware_Controllers_Frontend_PaypalUnifiedV2 $controller)
    {
        $headers = $controller->Response()->getHeaders();

        $location = array_filter($headers, function ($item) {
            if ($item['name'] === 'Location') {
                return $item;
            }
        });

        return \reset($location);
    }
}