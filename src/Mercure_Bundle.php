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
namespace Symfony\Bundle\Mercure_Bundle;

use Symfony\Bundle\Mercure_Bundle\Dependency_Injection\Compiler_Pass\Stimulus_Helper_Pass;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Compiler\Pass_Config;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Http_Kernel\Bundle\Bundle;
use Symfony\Component\Mercure\Authorization;
/**
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class Mercure_Bundle extends Bundle
{
    public function build(Container_Builder $container): void
    {
        parent::build($container);
        $container->add_compiler_pass(new Stimulus_Helper_Pass());
        $container->add_compiler_pass(new class implements Compiler_Pass_Interface
        {
            public function process(Container_Builder $container): void
            {
                if (!$container->has_definition(Authorization::class)) {
                    return;
                }
                $definition = $container->get_definition(Authorization::class);
                if (null === $definition->get_argument(1) && $container->has_parameter('session.storage.options')) {
                    $definition->set_argument(1, $container->get_parameter('session.storage.options')['cookie_lifetime'] ?? null);
                }
            }
        }, Pass_Config::TYPE_BEFORE_REMOVING);
    }
}