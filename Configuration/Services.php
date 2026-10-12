<?php

declare(strict_types=1);

namespace GeorgRinger\Linkvalidator2;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionMatcherInterface;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    // Applies to checkers of all extensions, not only to the ones defined in Services.yaml
    $containerBuilder->registerForAutoconfiguration(LinkCheckerInterface::class)->addTag('linkvalidator2.checker');
    $containerBuilder->registerForAutoconfiguration(ExclusionMatcherInterface::class)->addTag('linkvalidator2.exclusion_matcher');
};
