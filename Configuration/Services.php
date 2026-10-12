<?php

declare(strict_types=1);

namespace GeorgRinger\Linkvalidator2;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionMatcherInterface;
use GeorgRinger\Linkvalidator2\Widget\BrokenLinksWidget;
use TYPO3\CMS\Dashboard\WidgetRegistry;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    // Applies to checkers of all extensions, not only to the ones defined in Services.yaml
    $containerBuilder->registerForAutoconfiguration(LinkCheckerInterface::class)->addTag('linkvalidator2.checker');
    $containerBuilder->registerForAutoconfiguration(ExclusionMatcherInterface::class)->addTag('linkvalidator2.exclusion_matcher');

    // The dashboard is optional. The widget is not part of the service sweep of Services.yaml and
    // is only registered if EXT:dashboard is there, which a WidgetRegistry tells.
    if ($containerBuilder->hasDefinition(WidgetRegistry::class)) {
        $container->services()->set('dashboard.widget.linkvalidator2.brokenLinks')
            ->class(BrokenLinksWidget::class)
            ->autowire()
            ->tag('dashboard.widget', [
                'identifier' => 'linkvalidator2BrokenLinks',
                'groupNames' => 'general',
                'title' => 'linkvalidator2.widget:widget.title',
                'description' => 'linkvalidator2.widget:widget.description',
                'iconIdentifier' => 'content-widget-list',
                'height' => 'medium',
                'width' => 'medium',
            ]);
    }
};
