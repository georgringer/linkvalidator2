<?php

declare(strict_types=1);

namespace TYPO3\CMS\Linkvalidator2;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Linkvalidator2\Check\LinkCheckerInterface;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    // Applies to checkers of all extensions, not only to the ones defined in Services.yaml
    $containerBuilder->registerForAutoconfiguration(LinkCheckerInterface::class)->addTag('linkvalidator2.checker');
};
