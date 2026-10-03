<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Runtime\Config\MqttServerUrl;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Console\StewartCommand;
use Stewart\Runtime\Control\ControlPlaneFactory;
use Stewart\Runtime\Control\SnapshotAssembler;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Logging\LoggerFactory;
use Stewart\Store\StoreHealth;

final class PackageDependencyRules
{
    public function testContractsReachesOnlyItselfAndCron(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Contracts'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Cron'),
            )
            ->because('apps and generated entity classes must not drag in a transport or a process model');
    }

    public function testClientIsUsableWithoutTheRuntime(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Client'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime'),
                Selector::inNamespace('Stewart\Store'),
                Selector::inNamespace('Stewart\Codegen'),
                Selector::inNamespace('App'),
            );
    }

    public function testStoreCoreKnowsNoBackend(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Store'))
            ->excluding(TestCodeSelector::selectTestCode(), Selector::inNamespace('Stewart\Store\Redis'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Store'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
            )
            ->excluding(Selector::inNamespace('Stewart\Store\Redis'))
            ->because('the scoping and the encoding are the same whatever holds the bytes');
    }

    public function testRuntimeReachesNoBackendDirectly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Store\Redis'),
                Selector::inNamespace('Amp\Redis'),
                Selector::inNamespace('Stewart\Mqtt'),
            )
            ->because('a backend is an optional install, resolved from the URL scheme at boot');
    }

    public function testMqttClientReachesRuntimeOnlyThroughItsPort(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Mqtt'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Mqtt'),
                Selector::inNamespace('Stewart\Runtime\Broker\Mqtt'),
                Selector::classname(MqttConfig::class),
                Selector::classname(MqttServerUrl::class),
                Selector::classname(BackoffPolicy::class),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Amp'),
                Selector::inNamespace('Psr\Log'),
            )
            ->because('the client plugs into the broker through the MqttLink port and knows nothing else of the daemon');
    }

    public function testSupportLeansOnContractsAlone(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Support'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Amp'),
                Selector::inNamespace('Revolt'),
            )
            ->because('every framework package shares the helpers, so they cannot know any of them');
    }

    /** PHPStan reports `@internal` only across root namespaces, so inside `Stewart\*` this rule enforces it. */
    public function testOnlyTheFrameworkReachesSupport(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::all())
            ->excluding(
                TestCodeSelector::selectTestCode(),
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Stewart\Runtime'),
                Selector::inNamespace('Stewart\Client'),
                Selector::inNamespace('Stewart\Store'),
                Selector::inNamespace('Stewart\Codegen'),
                Selector::inNamespace('Stewart\Mqtt'),
            )
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Stewart\Support'))
            ->because('apps and generated code are written against the public contract, which is what 1.0 freezes');
    }

    public function testAppsSeeOnlyTheContracts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime'),
                Selector::inNamespace('Stewart\Store'),
            )
            ->because('an automation asks for contract types; which scope a Store is, and what holds it, is wiring');
    }

    public function testCodegenNeedsNothingTheDaemonOwns(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Codegen'))
            ->excluding(TestCodeSelector::selectTestCode(), Selector::inNamespace('Stewart\Codegen\Console'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Codegen'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Client'),
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Nette\PhpGenerator'),
                Selector::inNamespace('Psr\Log'),
            )
            ->because('generation is a development-time tool over a websocket, not part of the running daemon');
    }

    public function testGenerateUsesRuntimeOnlyForConfigAndWiring(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Codegen\Console'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Codegen'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Client'),
                Selector::inNamespace('Stewart\Runtime\Config'),
                Selector::classname(StewartCommand::class),
                Selector::classname(LoggerFactory::class),
                Selector::inNamespace('Symfony\Component\Console'),
                Selector::inNamespace('Psr\Log'),
            )
            ->because('the command is a thin console adapter; generation itself stays free of the daemon');
    }

    public function testCodegenAnalysisKnowsNothingOfTheEmittedCode(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('Stewart\Codegen\Snapshot'),
                Selector::inNamespace('Stewart\Codegen\Entity'),
                Selector::inNamespace('Stewart\Codegen\Attribute'),
                Selector::inNamespace('Stewart\Codegen\Service'),
                Selector::inNamespace('Stewart\Codegen\Model'),
                Selector::inNamespace('Stewart\Codegen\Php'),
            )
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Codegen\Emitter'),
                Selector::inNamespace('Stewart\Codegen\Output'),
                Selector::inNamespace('Nette'),
            )
            ->because('what Home Assistant offers is decided before any PHP is printed');
    }

    public function testCodegenEmittersWorkFromTheModelOnly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Codegen\Emitter'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Stewart\Codegen\Snapshot'))
            ->because('emitters print the model; reading raw Home Assistant data is the analysis side');
    }

    public function testRuntimeKnowsNoGenerator(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Codegen'),
                Selector::inNamespace('Nette'),
            )
            ->because('a production install has no codegen package, and the daemon must still start');
    }

    public function testGeneratedCodeLeansOnContractsAlone(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('App\Generated'),
                Selector::inNamespace('Stewart\Codegen\Tests\Fixtures\Expected'),
                Selector::inNamespace('Stewart\Runtime\Tests\Fixtures\Generated\Code'),
            )
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\Generated'),
                Selector::inNamespace('Stewart\Codegen\Tests\Fixtures\Expected'),
                Selector::inNamespace('Stewart\Runtime\Tests\Fixtures\Generated\Code'),
                Selector::inNamespace('Stewart\Contracts'),
            )
            ->because('generated classes are handed to an automation, and drag in nothing else');
    }

    public function testRuntimeKnowsNoParticularApp(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->because('the daemon runs whatever apps it discovers');
    }

    public function testFrameworkSourceNeverReachesTestCode(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Stewart\Client'),
                Selector::inNamespace('Stewart\Store'),
                Selector::inNamespace('Stewart\Codegen'),
                Selector::inNamespace('Stewart\Runtime'),
                Selector::inNamespace('Stewart\Mqtt'),
            )
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(TestCodeSelector::selectTestCode())
            ->because('a split package installs without its tests or the testing package');
    }

    public function testTestingKnowsNoRuntimeOrGenerator(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Testing'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime'),
                Selector::inNamespace('Stewart\Codegen'),
            )
            ->because('the lower packages test with these fakes, so they cannot pull in the top of the graph');
    }

    public function testIpcVocabularyLeansOnContractsAndTheModel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime\Ipc'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Ipc'),
                Selector::inNamespace('Stewart\Runtime\Model'),
                Selector::inNamespace('Stewart\Runtime\Lifecycle'),
                Selector::inNamespace('Stewart\Runtime\Exception'),
                Selector::inNamespace('Stewart\Runtime\Json'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Amp\Sync'),
                Selector::inNamespace('Symfony\Component\Finder'),
                Selector::classname(StoreHealth::class),
            )
            ->because('a configuration or process-model change must not be a wire change');
    }

    public function testWireMapperLeansOnContractsAlone(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime\Json'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Json'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
            )
            ->because('both wires share the mapper, so it cannot know either of them');
    }

    public function testModelLeansOnContractsAlone(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime\Model'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Model'),
                Selector::inNamespace('Stewart\Contracts'),
            )
            ->because('the model is what both wires carry, so it cannot depend on either process');
    }

    public function testLifecycleVocabularyLeansOnContractsAlone(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime\Lifecycle'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Lifecycle'),
                Selector::inNamespace('Stewart\Contracts'),
            )
            ->because('both wires and every process state machine name phases in it');
    }

    public function testControlProtocolIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime\Control\Protocol'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Control\Protocol'),
                Selector::inNamespace('Stewart\Runtime\Lifecycle'),
                Selector::inNamespace('Stewart\Runtime\Model'),
                Selector::classname(ControlException::class),
                Selector::classname(ControlError::class),
                Selector::inNamespace('Stewart\Runtime\Json'),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
                Selector::classname(StoreHealth::class),
            )
            ->because('the control socket is a schema; the process model reports into it without reaching the server');
    }

    public function testNothingBelowTheControlPlaneReachesIt(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Broker'),
                Selector::inNamespace('Stewart\Runtime\Worker'),
                Selector::inNamespace('Stewart\Runtime\Dispatch'),
                Selector::inNamespace('Stewart\Runtime\Schedule'),
                Selector::inNamespace('Stewart\Runtime\Scope'),
                Selector::inNamespace('Stewart\Runtime\Ipc'),
                Selector::inNamespace('Stewart\Runtime\Model'),
                Selector::inNamespace('Stewart\Runtime\Lifecycle'),
            )
            ->excluding(TestCodeSelector::selectTestCode())
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Control\Server'),
                Selector::inNamespace('Stewart\Runtime\Control\Client'),
                Selector::inNamespace('Stewart\Runtime\Control\Assembler'),
                Selector::classname(SnapshotAssembler::class),
                Selector::classname(ControlPlaneFactory::class),
            )
            ->because('the control plane observes the process model; only the container wires the two together');
    }

    public function testControlClientReachesDaemonOnlyViaWire(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Stewart\Runtime\Control\Client'))
            ->excluding(TestCodeSelector::selectTestCode())
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Stewart\Runtime\Control\Client'),
                Selector::inNamespace('Stewart\Runtime\Control\Protocol'),
                Selector::inNamespace('Stewart\Runtime\Lifecycle'),
                Selector::classname(ControlException::class),
                Selector::classname(ControlConfig::class),
                Selector::classname(ControlAddress::class),
                Selector::classname(ProjectRoot::class),
                Selector::inNamespace('Stewart\Contracts'),
                Selector::inNamespace('Stewart\Support'),
                Selector::inNamespace('Amp'),
            )
            ->because('a status client is a client of the control socket, not a part of the process model');
    }
}
