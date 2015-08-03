<?php

namespace Rx\Observable;

use InvalidArgumentException;
use Rx\Disposable\CallbackDisposable;
use Rx\ObserverInterface;
use Rx\Observer\AutoDetachObserver;

class AnonymousObservable extends BaseObservable
{
    private $subscribeAction;

    public function __construct($subscribeAction)
    {
        if ( ! is_callable($subscribeAction)) {
            throw new InvalidArgumentException("Action should be a callable.");
        }

        $this->subscribeAction = $subscribeAction;
    }

    protected function finishSubscribe(ObserverInterface $observer, $scheduler = null)
    {
        $subscribeAction = $this->subscribeAction;

        $autoDetachObserver = new AutoDetachObserver($observer);

        $autoDetachObserver->setDisposable($subscribeAction($autoDetachObserver, $scheduler));

        return new CallbackDisposable(function() use ($autoDetachObserver) {
            $autoDetachObserver->dispose();
        });
    }

    protected function doStart($scheduler)
    {
        // todo: remove from base
    }
}
