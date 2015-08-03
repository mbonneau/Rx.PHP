<?php

namespace Rx\Observable;

use Exception;
use InvalidArgumentException;
use Rx\DisposableInterface;
use Rx\ObserverInterface;
use Rx\ObservableInterface;
use Rx\Observer\CallbackObserver;
use Rx\Scheduler\ImmediateScheduler;
use Rx\Disposable\CompositeDisposable;
use Rx\Disposable\SingleAssignmentDisposable;
use Rx\SchedulerInterface;
use Rx\Subject\Subject;
use Rx\Disposable\RefCountDisposable;
use Rx\Disposable\EmptyDisposable;
use Rx\Disposable\CallbackDisposable;

abstract class BaseObservable implements ObservableInterface
{
    protected $observers = array();
    protected $started = false;
    private $disposable = null;

    /**
     * Override this to do your own subscription stuff
     * it will fixup your subscribe call if you use callbacks or an ObserverInterface
     *
     * @param ObserverInterface $observer
     * @param null $scheduler
     * @return CallbackDisposable
     */
    protected function finishSubscribe(ObserverInterface $observer, $scheduler = null)
    {
        $this->observers[] = $observer;

        if ( ! $this->started) {
            $this->start($scheduler);
        }

        $observable = $this;

        return new CallbackDisposable(function() use ($observer, $observable) {
            $observable->removeObserver($observer);
        });
    }

    /**
     * @inheritdoc
     */
    function subscribe($observerOrOnNext = null, $schedulerOrOnError = null, $onCompleted = null, $schedulerIfUsingCallbacks = null)
    {
        list($observer, $scheduler) = self::getObserverAndSchedulerForSubscribe($observerOrOnNext, $schedulerOrOnError, $onCompleted, $schedulerIfUsingCallbacks);

        return $this->finishSubscribe($observer, $scheduler);
    }

    public static function getObserverAndSchedulerForSubscribe($observerOrOnNext, $schedulerOrOnError = null, $onCompleted = null, $schedulerIfUsingCallbacks = null) {
        if ($observerOrOnNext instanceof ObserverInterface) {
            if ($onCompleted !== null || $schedulerIfUsingCallbacks !== null) {
                throw new InvalidArgumentException("Cannot pass onCompleted or schedulerIfUsingCallbacks parameters if using an ObservableInterface to subscribe.");
            }
            if ($schedulerOrOnError !== null && !($schedulerOrOnError instanceof SchedulerInterface)) {
                throw new InvalidArgumentException("Second argument to subscribe must be null or ScheduleInterface");
            }

            return [$observerOrOnNext, $schedulerOrOnError];
        }

        if ($observerOrOnNext === null || is_callable($observerOrOnNext)) {
            if ($schedulerOrOnError !== null && !is_callable($schedulerOrOnError)) {
                throw new InvalidArgumentException("Second argument to subscribe must be callable if first is not an ObserverInterface");
            }
            if ($onCompleted !== null && !is_callable($onCompleted)) {
                throw new InvalidArgumentException("Third argument to subscribe must be callable");
            }
            if ($schedulerIfUsingCallbacks && !($schedulerIfUsingCallbacks instanceof SchedulerInterface)) {
                throw new InvalidArgumentException("Invalid argument for scheduler");
            }

            $observer = new CallbackObserver($observerOrOnNext, $schedulerOrOnError, $onCompleted);
            return [$observer, $schedulerIfUsingCallbacks];
        }

        throw new InvalidArgumentException("First argument must be callable or ObserverInterface");
    }

    /**
     * @internal
     */
    public function removeObserver(ObserverInterface $observer)
    {
        $key = array_search($observer, $this->observers);

        if (false === $key) {
            return false;
        }

        unset($this->observers[$key]);

        return true;
    }

    /**
     * @deprecated
     */
    public function subscribeCallback($onNext = null, $onError = null, $onCompleted = null, $scheduler = null)
    {
        return $this->subscribe($onNext, $onError, $onCompleted, $scheduler);
    }

    private function start($scheduler = null)
    {
        if (null === $scheduler) {
            $scheduler = new ImmediateScheduler();
        }

        $this->started = true;

        $this->doStart($scheduler);
    }

    abstract protected function doStart($scheduler);

    public function select($selector)
    {
        if ( ! is_callable($selector)) {
            throw new InvalidArgumentException('Selector should be a callable.');
        }

        $currentObservable = $this;

        // todo: add scheduler
        return new AnonymousObservable(function($observer, $scheduler) use ($currentObservable, $selector) {
            $selectObserver = new CallbackObserver(
                function($nextValue) use ($observer, $selector) {
                    $value = null;
                    try {
                        $value = $selector($nextValue);
                    } catch (Exception $e) {
                        $observer->onError($e);
                    }
                    $observer->onNext($value);
                },
                function($error) use ($observer) {
                    $observer->onError($error);
                },
                function() use ($observer) {
                    $observer->onCompleted();
                }
            );

            return $currentObservable->subscribe($selectObserver, $scheduler);
        });
    }

    public function where($predicate)
    {
        if ( ! is_callable($predicate)) {
            throw new InvalidArgumentException('Predicate should be a callable.');
        }

        $currentObservable = $this;

        // todo: add scheduler
        return new AnonymousObservable(function($observer, $scheduler) use ($currentObservable, $predicate) {
            $selectObserver = new CallbackObserver(
                function($nextValue) use ($observer, $predicate) {
                    $shouldFire = false;
                    try {
                        $shouldFire = $predicate($nextValue);
                    } catch (Exception $e) {
                        $observer->onError($e);
                    }

                    if ($shouldFire) {
                        $observer->onNext($nextValue);
                    }
                },
                function($error) use ($observer) {
                    $observer->onError($error);
                },
                function() use ($observer) {
                    $observer->onCompleted();
                }
            );

            return $currentObservable->subscribe($selectObserver, $scheduler);
        });
    }

    public function merge(ObservableInterface $otherObservable, $scheduler = null)
    {
        return self::mergeAll(
            self::fromArray(array($this, $otherObservable), $scheduler)
        );
    }

    public function selectMany($selector)
    {
        if ( ! is_callable($selector)) {
            throw new InvalidArgumentException('Selector should be a callable.');
        }

        return self::mergeAll($this->select($selector));
    }

    /**
     * Merges an observable sequence of observables into an observable sequence.
     *
     * @param ObservableInterface $observables
     *
     * @return ObserverInterface
     */
    public static function mergeAll(ObservableInterface $sources)
    {
        // todo: add scheduler
        return new AnonymousObservable(function($observer, $scheduler) use ($sources) {
            $group              = new CompositeDisposable();
            $isStopped          = false;
            $sourceSubscription = new SingleAssignmentDisposable();

            $group->add($sourceSubscription);

            $sourceSubscription->setDisposable(
                $sources->subscribeCallback(
                    function($innerSource) use (&$group, &$isStopped, $observer, &$scheduler) {
                        $innerSubscription = new SingleAssignmentDisposable();
                        $group->add($innerSubscription);

                        $innerSubscription->setDisposable(
                            $innerSource->subscribeCallback(
                                function($nextValue) use ($observer) {
                                    $observer->onNext($nextValue);
                                },
                                function($error) use ($observer) {
                                    $observer->onError($error);
                                },
                                function() use (&$group, &$innerSubscription, &$isStopped, $observer) {
                                    $group->remove($innerSubscription);

                                    if ($isStopped && $group->count() === 1) {
                                        $observer->onCompleted();
                                    }
                                },
                                $scheduler
                            )
                        );
                    },
                    function($error) use ($observer) {
                        $observer->onError($error);
                    },
                    function() use (&$group, &$isStopped, $observer) {
                        $isStopped = true;
                        if ($group->count() === 1) {
                            $observer->onCompleted();
                        }
                    },
                    $scheduler
                )
            );

            return $group;
        });
    }

    public static function fromArray(array $array, $scheduler = null)
    {
        return new AnonymousObservable(function ($observer, $scheduler) use ($array) {
            return (new ArrayObservable($array))->subscribe($observer, $scheduler);
        });
    }

    public function skip($count)
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Count must be >= 0');
        }

        $currentObservable = $this;

        return new AnonymousObservable(function($observer, $scheduler) use ($currentObservable, $count) {
            $remaining = $count;

            return $currentObservable->subscribeCallback(
                function($nextValue) use ($observer, &$remaining) {
                    if ($remaining <= 0) {
                        $observer->onNext($nextValue);
                    } else {
                        $remaining--;
                    }
                },
                array($observer, 'onError'),
                array($observer, 'onCompleted')
            );
        });
    }

    public function take($count)
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Count must be >= 0');
        }

        if ($count === 0) {
            return new EmptyObservable();
        }

        $currentObservable = $this;

        return new AnonymousObservable(function($observer, $scheduler) use ($currentObservable, $count) {
            $remaining = $count;

            return $currentObservable->subscribeCallback(
                function($nextValue) use ($observer, &$remaining) {
                    if ($remaining > 0) {
                        $remaining--;
                        $observer->onNext($nextValue);
                        if ($remaining === 0) {
                            $observer->onCompleted();
                        }
                    }
                },
                array($observer, 'onError'),
                array($observer, 'onCompleted'),
                $scheduler
            );
        });
    }

    public function groupBy($keySelector, $elementSelector = null, $keySerializer = null)
    {
        return $this->groupByUntil($keySelector, $elementSelector, function() {

            // observable that never calls
            return new AnonymousObservable(function() {
                // todo?
                return new EmptyDisposable();
            });
        }, $keySerializer);
    }

    public function groupByUntil($keySelector, $elementSelector = null, $durationSelector = null, $keySerializer = null)
    {
        $currentObservable = $this;

        if (null === $elementSelector) {
            $elementSelector = function($elem) { return $elem; };
        } else if ( ! is_callable($elementSelector)) {
            throw new InvalidArgumentException('Element selector should be a callable.');
        }

        if (null === $keySerializer) {
            $keySerializer = function($elem) { return $elem; };
        } else if ( ! is_callable($keySerializer)) {
            throw new InvalidArgumentException('Key serializer should be a callable.');
        }

        return new AnonymousObservable(function($observer, $scheduler) use ($currentObservable, $keySelector, $elementSelector, $durationSelector, $keySerializer) {
            $map = array();
            $groupDisposable = new CompositeDisposable();
            $refCountDisposable = new RefCountDisposable($groupDisposable);

            $groupDisposable->add($currentObservable->subscribeCallback(
                function($value) use (&$map, $keySelector, $elementSelector, $durationSelector, $observer, $keySerializer, $groupDisposable, $refCountDisposable){
                    try {
                        $key = $keySelector($value);
                        $serializedKey = $keySerializer($key);
                    } catch (Exception $e) {
                        foreach ($map as $groupObserver) {
                            $groupObserver->onError($e);
                        }
                        $observer->onError($e);

                        return;
                    }

                    $fireNewMapEntry = false;

                    try {
                        if ( ! isset($map[$serializedKey])) {
                            $map[$serializedKey] = new Subject();
                            $fireNewMapEntry = true;
                        }
                        $writer = $map[$serializedKey];

                    } catch (Exception $e) {
                        foreach ($map as $groupObserver) {
                            $groupObserver->onError($e);
                        }
                        $observer->onError($e);

                        return;
                    }

                    if ($fireNewMapEntry) {
                        $group = new GroupedObservable($key, $writer, $refCountDisposable);
                        $durationGroup = new GroupedObservable($key, $writer);

                        try {
                            $duration = $durationSelector($durationGroup);
                        } catch (Exception $e) {
                            foreach ($map as $groupObserver) {
                                $groupObserver->onError($e);
                            }
                            $observer->onError($e);

                            return;
                        }

                        $observer->onNext($group);
                        $md = new SingleAssignmentDisposable();
                        $groupDisposable->add($md);
                        $expire = function() use (&$map, &$md, $serializedKey, &$writer, &$groupDisposable) {
                            if (isset($map[$serializedKey])) {
                                unset($map[$serializedKey]);
                                $writer->onCompleted();
                            }
                            $groupDisposable->remove($md);
                        };

                        $md->setDisposable(
                            $duration->take(1)->subscribeCallback(
                                function(){},
                                function(Exception $exception) use ($map, $observer){
                                    foreach ($map as $writer) {
                                        $writer->onError($exception);
                                    }

                                    $observer->onError($exception);
                                },
                                function() use ($expire) {
                                    $expire();
                                }
                            )
                        );
                    }

                    try {
                        $element = $elementSelector($value);
                    } catch (Exception $exception) {
                        foreach ($map as $writer) {
                            $writer->onError($exception);
                        }

                        $observer->onError($exception);
                        return;
                    }
                    $writer->onNext($element);
                },
                function(Exception $error) use (&$map, $observer) {
                    foreach ($map as $writer) {
                        $writer->onError($error);
                    }

                    $observer->onError($error);
                },
                function() use (&$map, $observer) {
                    foreach ($map as $writer) {
                        $writer->onCompleted();
                    }

                    $observer->onCompleted();
                },
                $scheduler
            ));

            return $refCountDisposable;
        });
    }
}
