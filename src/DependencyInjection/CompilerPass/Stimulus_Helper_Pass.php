<?php

/*
 * This file is part of the Mercure Component project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare (strict_types=1);
namespace Symfony\Bundle\Mercure_Bundle\Dependency_Injection\Compiler_Pass;

use Symfony\Component\Dependency_Injection\Alias;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\UX\Turbo\Bridge\Mercure\Broadcaster;
/**
 * Registers a dynamic alias to use a service from WebpackEncoreBundle or StimulusBundle.
 *
 * Depending on the version of symfony/ux-turbo installed, one of these bundles
 * will be available.
 */
final class Stimulus_Helper_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        if (!class_exists(Broadcaster::class)) {
            return;
        }
        if ($container->has_definition('webpack_encore.twig_stimulus_extension')) {
            $id = 'webpack_encore.twig_stimulus_extension';
        } else {
            $id = 'stimulus.helper';
        }
        $container->set_alias('turbo.mercure.stimulus_helper', new Alias($id));
    }
}