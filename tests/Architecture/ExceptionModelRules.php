<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Stewart\Contracts\Exception\StewartException;

final class ExceptionModelRules
{
    public function testEveryFrameworkThrowableHasTheOneRoot(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(Selector::isThrowable(), Selector::inNamespace('Stewart')))
            ->excluding(
                Selector::classname(StewartException::class),
                TestCodeSelector::selectTestCode(),
            )
            ->should()
            ->extend()
            ->classes(Selector::classname(StewartException::class))
            ->because('a single catch (StewartException) has to see every error the framework raises');
    }

    public function testAreaClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::extends(StewartException::class))
            ->should()
            ->beFinal()
            ->because('each failure has a reason case, and a subclass would add failures without one');
    }

    public function testAreaClassesLiveInPackageExceptionNamespace(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::extends(StewartException::class))
            ->excluding(TestCodeSelector::selectTestCode())
            ->should()
            ->beNamed('/^Stewart\\\\[A-Za-z]+\\\\Exception\\\\[A-Za-z]+Exception$/', true)
            ->because('each area class is paired by name with its reason enum there');
    }
}
