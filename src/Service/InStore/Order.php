<?php
namespace CultureKings\Afterpay\Service\InStore;

use CultureKings\Afterpay\Exception\ApiException;
use CultureKings\Afterpay\Model;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;

/**
 * Class Order
 * @package CultureKings\Afterpay\Service\InStore
 */
class Order extends AbstractService
{
    const ERROR_DECLINED = 402;
    const ERROR_MINIMUM_NOT_MET = 402;
    const ERROR_EXCEED_PREAPPROVAL = 402;
    const ERROR_CONFLICT = 409;
    const ERROR_INVALID_CODE = 412;

    /**
     * @param Model\InStore\Order $order
     * @param HandlerStack|null   $stack
     *
     * @return array|\JMS\Serializer\scalar|object
     */
    public function create(Model\InStore\Order $order, HandlerStack $stack = null)
    {
        try {
            $params = $this->generateParams($order, $stack);

            $result = $this->getClient()->post('orders', $params);

            return $this->getSerializer()->deserialize(
                (string) $result->getBody(),
                Model\InStore\Order::class,
                'json'
            );
        } catch (BadResponseException $e) {
            throw new ApiException(
                $this->getSerializer()->deserialize(
                    (string) $e->getResponse()->getBody(),
                    Model\ErrorResponse::class,
                    'json'
                ),
                $e
            );
        }
    }

    /**
     * @param Model\InStore\Reversal $orderReversal
     * @param HandlerStack|null      $stack
     *
     * @return array|\JMS\Serializer\scalar|object
     */
    public function reverse(Model\InStore\Reversal $orderReversal, HandlerStack $stack = null)
    {
        try {
            $params = $this->generateParams($orderReversal, $stack);

            $result = $this->getClient()->post('orders/reverse', $params);

            return $this->getSerializer()->deserialize(
                (string) $result->getBody(),
                Model\InStore\Reversal::class,
                'json'
            );
        } catch (BadResponseException $e) {
            throw new ApiException(
                $this->getSerializer()->deserialize(
                    (string) $e->getResponse()->getBody(),
                    Model\ErrorResponse::class,
                    'json'
                ),
                $e
            );
        }
    }

    /**
     * Helper method to automatically attempt to reverse an order if an error occurs.
     *
     * Order reversal model does not have to be passed in and will be automatically generated if not.
     *
     * @param Model\InStore\Order         $order
     * @param Model\InStore\Reversal|null $orderReversal
     * @param HandlerStack|null           $stack
     *
     * @return array|\JMS\Serializer\scalar|object
     */
    public function createOrReverse(
        Model\InStore\Order $order,
        Model\InStore\Reversal $orderReversal = null,
        HandlerStack $stack = null
    ) {
        try {
            return $this->create($order, $stack);
        } catch (ApiException $e) {
            // http://docs.afterpay.com.au/instore-api-v1.html#create-order
            // Should a success or error response (with exception to 409 conflict) not be received,
            // the POS should queue the request ID for reversal
            if ($e->getErrorResponse()->getErrorCode() == self::ERROR_CONFLICT) {
                throw $e;
            }
        } catch (RequestException $e) {
            // a timeout or other exception has occurred. attempt a reversal
        }

        $now = new \DateTime();
        if ($orderReversal === null) {
            $orderReversal = new Model\InStore\Reversal();
            $orderReversal->setReversingRequestId($order->getRequestId());
            $orderReversal->setRequestedAt($now);
        }

        return $this->reverse($orderReversal, $stack);
    }
}