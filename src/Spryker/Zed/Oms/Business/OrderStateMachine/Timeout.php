<?php

/**
 * (c) Spryker Systems GmbH copyright protected
 */

namespace Spryker\Zed\Oms\Business\OrderStateMachine;

use DateInterval;
use Spryker\Zed\Oms\Persistence\OmsQueryContainerInterface;
use Spryker\Zed\Oms\Business\Process\ProcessInterface;
use Spryker\Zed\Oms\Business\Process\StateInterface;
use Spryker\Zed\Oms\Business\Process\EventInterface;
use Orm\Zed\Sales\Persistence\SpySalesOrderItem;
use Orm\Zed\Oms\Persistence\SpyOmsEventTimeout;
use Orm\Zed\Oms\Persistence\SpyOmsEventTimeoutQuery;
use DateTime;
use Exception;
use ErrorException;
use Propel\Runtime\Collection\ObjectCollection;
use Spryker\Shared\Library\Log;

class Timeout implements TimeoutInterface
{

    /**
     * @var OmsQueryContainerInterface
     */
    protected $queryContainer;

    /**
     * @var DateTime[]
     */
    protected $eventToTimeoutBuffer = [];

    /**
     * @var StateInterface[]
     */
    protected $stateIdToModelBuffer = [];

    /**
     * @param \Spryker\Zed\Oms\Persistence\OmsQueryContainerInterface $queryContainer
     */
    public function __construct(OmsQueryContainerInterface $queryContainer)
    {
        $this->queryContainer = $queryContainer;
    }

    /**
     * @param OrderStateMachineInterface $orderStateMachine
     *
     * @return int
     */
    public function checkTimeouts(OrderStateMachineInterface $orderStateMachine)
    {
        $orderItems = $this->findItemsWithExpiredTimeouts();

        $countAffectedItems = $orderItems->count();

        $groupedOrderItems = $this->groupItemsByEvent($orderItems);

        foreach ($groupedOrderItems as $event => $orderItems) {
            $orderStateMachine->triggerEvent($event, $orderItems, []);
        }

        return $countAffectedItems;
    }

    /**
     * @param \Spryker\Zed\Oms\Business\Process\ProcessInterface $process
     * @param \Orm\Zed\Sales\Persistence\SpySalesOrderItem $orderItem
     * @param DateTime $currentTime
     *
     * @throws Exception
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    public function setNewTimeout(ProcessInterface $process, SpySalesOrderItem $orderItem, DateTime $currentTime)
    {
        $targetStateEntity = $orderItem->getState();

        $targetState = $this->getStateFromProcess($targetStateEntity->getName(), $process);

        if ($targetState->hasTimeoutEvent()) {
            $events = $targetState->getTimeoutEvents();

            $handledEvents = [];
            foreach ($events as $event) {
                if (in_array($event->getName(), $handledEvents)) {
                    continue;
                }

                $handledEvents[] = $event->getName();
                $timeoutDate = $this->calculateTimeoutDateFromEvent($currentTime, $event);

                (new SpyOmsEventTimeout())
                    ->setTimeout($timeoutDate)
                    ->setOrderItem($orderItem)
                    ->setState($targetStateEntity)
                    ->setEvent($event->getName())
                    ->save();
            }
        }
    }

    /**
     * @param \Spryker\Zed\Oms\Business\Process\ProcessInterface $process
     * @param string $stateId
     * @param \Orm\Zed\Sales\Persistence\SpySalesOrderItem $orderItem
     *
     * @throws Exception
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    public function dropOldTimeout(ProcessInterface $process, $stateId, SpySalesOrderItem $orderItem)
    {
        $sourceState = $this->getStateFromProcess($stateId, $process);

        if ($sourceState->hasTimeoutEvent()) {
            SpyOmsEventTimeoutQuery::create()
                ->filterByOrderItem($orderItem)
                ->delete();
        }
    }

    /**
     * @param DateTime $currentTime
     * @param \Spryker\Zed\Oms\Business\Process\EventInterface $event
     *
     * @return DateTime
     */
    protected function calculateTimeoutDateFromEvent(DateTime $currentTime, EventInterface $event)
    {
        $currentTime = clone $currentTime;

        if (!isset($this->eventToTimeoutBuffer[$event->getName()])) {
            $timeout = $event->getTimeout();
            $interval = DateInterval::createFromDateString($timeout);

            $this->validateTimeout($interval, $timeout);

            $this->eventToTimeoutBuffer[$event->getName()] = $currentTime->add($interval);

            Log::log($this->eventToTimeoutBuffer, 'timeout.log');
        }

        return $this->eventToTimeoutBuffer[$event->getName()];
    }

    /**
     * @param string $stateId
     * @param \Spryker\Zed\Oms\Business\Process\ProcessInterface $process
     *
     * @return \Spryker\Zed\Oms\Business\Process\StateInterface
     */
    protected function getStateFromProcess($stateId, ProcessInterface $process)
    {
        if (!isset($this->stateIdToModelBuffer[$stateId])) {
            $this->stateIdToModelBuffer[$stateId] = $process->getStateFromAllProcesses($stateId);
        }

        return $this->stateIdToModelBuffer[$stateId];
    }

    /**
     * @param \Propel\Runtime\Collection\ObjectCollection $orderItems
     *
     * @return array
     */
    protected function groupItemsByEvent(ObjectCollection $orderItems)
    {
        $groupedOrderItems = [];
        foreach ($orderItems as $orderItem) {
            $eventName = $orderItem->getEvent();
            if (!isset($groupedOrderItems[$eventName])) {
                $groupedOrderItems[$eventName] = [];
            }
            $groupedOrderItems[$eventName][] = $orderItem;
        }

        return $groupedOrderItems;
    }

    /**
     * @return \Propel\Runtime\Collection\ObjectCollection
     */
    protected function findItemsWithExpiredTimeouts()
    {
        $now = new DateTime('now');

        return $this->queryContainer->querySalesOrderItemsWithExpiredTimeouts($now)->find();
    }

    /**
     * @param \DateInterval $interval
     * @param mixed $timeout
     *
     * @throws ErrorException
     *
     * @return int
     */
    protected function validateTimeout($interval, $timeout)
    {
        $vars = get_object_vars($interval);
        $vSum = 0;
        foreach ($vars as $v) {
            $vSum += (int) $v;
        }
        if ($vSum === 0) {
            throw new ErrorException('Invalid format for timeout "' . $timeout . '"');
        }

        return $vSum;
    }

}
