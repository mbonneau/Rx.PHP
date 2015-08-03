<?php

namespace Rx;

interface ObservableInterface
{
    /**
     * @param $observerOrOnNext ObserverInterface|\Closure|null
     * @param $schedulerOrOnError SchedulerInterface|\Closure|null
     * @param $onCompleted \Closure|null
     * @param $schedulerIfUsingCallbacks \Closure|null
     *
     * @return DisposableInterface
     */
    function subscribe($observerOrOnNext = null, $schedulerOrOnError = null, $onCompleted = null, $schedulerIfUsingCallbacks = null);
}
