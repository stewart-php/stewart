<?php

declare(strict_types=1);

use Stewart\Client\HaClientFactory;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Attribute\AttributeCatalog;
use Stewart\Codegen\Attribute\AttributeKindInference;
use Stewart\Codegen\Attribute\AttributeSelector;
use Stewart\Codegen\Console\GenerateCommand;
use Stewart\Codegen\Console\GenerationOptionsFactory;
use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EntitiesRootEmitter;
use Stewart\Codegen\Emitter\Entity\DomainEntitiesEmitter;
use Stewart\Codegen\Emitter\Entity\EntityHandleEmitter;
use Stewart\Codegen\Emitter\Entity\EntityStateEmitter;
use Stewart\Codegen\Emitter\GeneratedCodePrinter;
use Stewart\Codegen\Emitter\ManifestEmitter;
use Stewart\Codegen\Emitter\ModelFileEmitter;
use Stewart\Codegen\Emitter\Service\DomainServicesEmitter;
use Stewart\Codegen\Emitter\Service\ServiceMethodEmitter;
use Stewart\Codegen\Emitter\ServicesRootEmitter;
use Stewart\Codegen\Emitter\SourceTree;
use Stewart\Codegen\Entity\EntityModelFactory;
use Stewart\Codegen\Entity\EntitySelector;
use Stewart\Codegen\Generator;
use Stewart\Codegen\Model\DomainCatalog;
use Stewart\Codegen\Model\GenerationModelFactory;
use Stewart\Codegen\Php\MemberReservations;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Codegen\Service\ServiceCatalogParser;
use Stewart\Codegen\Snapshot\HaClientSnapshotFetcher;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Snapshot\SnapshotFetcher;
use Stewart\Codegen\Snapshot\SnapshotFileReader;
use Stewart\Codegen\Snapshot\SnapshotFileWriter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire();

    $services->instanceof(ModelFileEmitter::class)->tag('stewart.codegen.model_emitter');
    $services->instanceof(DomainFileEmitter::class)->tag('stewart.codegen.domain_emitter');
    $services->instanceof(ReservesMemberNames::class)->tag('stewart.codegen.member_reserver');

    $services->set(GenerateCommand::class)->tag('console.command');
    $services->set(GenerationOptionsFactory::class);

    $services->set(SnapshotCodec::class);
    $services->set(EntityStateDecoder::class);
    $services->set(SnapshotFileReader::class)->public();
    $services->set(SnapshotFileWriter::class);
    $services->set(HaClientFactory::class);
    $services->set(HaClientSnapshotFetcher::class);
    $services->alias(SnapshotFetcher::class, HaClientSnapshotFetcher::class);

    $services->set(GenerationModelFactory::class);
    $services->set(AttributeKindInference::class);
    $services->set(EntitySelector::class);
    $services->set(EntityModelFactory::class);
    $services->set(AttributeSelector::class);
    $services->set(AttributeCatalog::class);
    $services->set(ServiceCatalogParser::class);
    $services->set(DomainCatalog::class);
    $services->set(MemberReservations::class)->arg('$reservers', tagged_iterator('stewart.codegen.member_reserver'));

    $services->set(Generator::class)->public();
    $services->set(SourceTree::class)
        ->arg('$modelEmitters', tagged_iterator('stewart.codegen.model_emitter'))
        ->arg('$domainEmitters', tagged_iterator('stewart.codegen.domain_emitter'));
    $services->set(GeneratedCodePrinter::class);
    $services->set(ServiceMethodEmitter::class);
    $services->set(EntitiesRootEmitter::class);
    $services->set(ServicesRootEmitter::class);
    $services->set(ManifestEmitter::class);
    $services->set(DomainEntitiesEmitter::class);
    $services->set(EntityHandleEmitter::class);
    $services->set(EntityStateEmitter::class);
    $services->set(DomainServicesEmitter::class);
};
