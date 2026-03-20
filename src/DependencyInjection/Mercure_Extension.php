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
namespace Symfony\Bundle\Mercure_Bundle\Dependency_Injection;

use Symfony\Bundle\Mercure_Bundle\Data_Collector\Mercure_Data_Collector;
use Symfony\Component\Config\Definition\Configuration_Interface;
use Symfony\Component\Dependency_Injection\Alias;
use Symfony\Component\Dependency_Injection\Argument\Iterator_Argument;
use Symfony\Component\Dependency_Injection\Compiler\Alias_Deprecated_Public_Services_Pass;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Container_Interface;
use Symfony\Component\Dependency_Injection\Definition;
use Symfony\Component\Dependency_Injection\Extension\Extension;
use Symfony\Component\Dependency_Injection\Reference;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Debug\Traceable_Hub;
use Symfony\Component\Mercure\Debug\Traceable_Publisher;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Mercure\Event_Subscriber\Set_Cookie_Subscriber;
use Symfony\Component\Mercure\Franken_Php_Hub;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Hub_Interface;
use Symfony\Component\Mercure\Hub_Registry;
use Symfony\Component\Mercure\Jwt\Callable_Token_Provider;
use Symfony\Component\Mercure\Jwt\Factory_Token_Provider;
use Symfony\Component\Mercure\Jwt\Lcobucci_Factory;
use Symfony\Component\Mercure\Jwt\Static_Jwt_Provider;
use Symfony\Component\Mercure\Jwt\Static_Token_Provider;
use Symfony\Component\Mercure\Jwt\Token_Factory_Interface;
use Symfony\Component\Mercure\Jwt\Token_Provider_Interface;
use Symfony\Component\Mercure\Messenger\Update_Handler;
use Symfony\Component\Mercure\Publisher;
use Symfony\Component\Mercure\Publisher_Interface;
use Symfony\Component\Mercure\Twig\Mercure_Extension as TwigMercureExtension;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\UX\Turbo\Bridge\Mercure\Broadcaster;
use Symfony\UX\Turbo\Bridge\Mercure\Turbo_Stream_Listen_Renderer;
use Twig\Environment;
use Twig\Extension\Abstract_Extension;
/**
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class Mercure_Extension extends Extension
{
    public function load(array $configs, Container_Builder $container): void
    {
        $configuration = $this->get_configuration($configs, $container);
        if (!$configuration instanceof Configuration_Interface) {
            return;
        }
        $config = $this->process_configuration($configuration, $configs);
        if (!$config['hubs']) {
            return;
        }
        $default_publisher = null;
        $default_hub_id = null;
        $traceable_hubs = [];
        $hubs = [];
        $default_hub_name = null;
        $enable_profiler = ($config['enable_profiler'] ?? $container->get_parameter('kernel.debug')) && class_exists(Stopwatch::class);
        foreach ($config['hubs'] as $name => $hub) {
            $builtin_hub = !isset($hub['url']);
            $token_factory = null;
            $token_provider = null;
            if (isset($hub['jwt'])) {
                if (isset($hub['jwt']['value'])) {
                    $token_provider = \sprintf('mercure.hub.%s.jwt.provider', $name);
                    $container->register($token_provider, Static_Token_Provider::class)->add_argument($hub['jwt']['value'])->add_tag('mercure.jwt.provider');
                    // TODO: remove the following definition in 0.4
                    $jwt_provider = \sprintf('mercure.hub.%s.jwt_provider', $name);
                    $jwt_provider_definition = $container->register($jwt_provider, Static_Jwt_Provider::class)->add_argument($hub['jwt']['value']);
                    $this->deprecate($jwt_provider_definition, 'The "%service_id%" service is deprecated. You should stop using it, as it will be removed in the future, use "' . $token_provider . '" instead.');
                } elseif (isset($hub['jwt']['provider'])) {
                    $token_provider = $hub['jwt']['provider'];
                } else {
                    if (isset($hub['jwt']['factory'])) {
                        $token_factory = $hub['jwt']['factory'];
                    } else {
                        // 'secret' must be set.
                        $token_factory = \sprintf('mercure.hub.%s.jwt.factory', $name);
                        $container->register($token_factory, Lcobucci_Factory::class)->add_argument($hub['jwt']['secret'])->add_argument($hub['jwt']['algorithm'])->add_argument(null)->add_argument($hub['jwt']['passphrase'])->add_tag('mercure.jwt.factory');
                    }
                    $container->register('.lazy.' . $token_factory, Token_Factory_Interface::class)->set_factory(['Closure', 'fromCallable'])->add_argument([new Reference($token_factory), 'create']);
                    $token_factory = '.lazy.' . $token_factory;
                    $token_provider = \sprintf('mercure.hub.%s.jwt.provider', $name);
                    $container->register($token_provider, Factory_Token_Provider::class)->add_argument(new Reference($token_factory))->add_argument($hub['jwt']['subscribe'] ?? [])->add_argument($hub['jwt']['publish'] ?? [])->add_tag('mercure.jwt.factory');
                    $container->register_alias_for_argument($token_factory, Token_Factory_Interface::class, $name);
                    $container->register_alias_for_argument($token_factory, Token_Factory_Interface::class, "{$name}Factory");
                    $container->register_alias_for_argument($token_factory, Token_Factory_Interface::class, "{$name}TokenFactory");
                }
            } elseif (isset($hub['jwt_provider'])) {
                $jwt_provider = $hub['jwt_provider'];
                $token_provider = \sprintf('mercure.hub.%s.jwt.provider', $name);
                $container->register($token_provider, Callable_Token_Provider::class)->add_argument(new Reference($jwt_provider))->add_tag('mercure.jwt.provider');
            }
            if (null !== $token_provider) {
                $container->register_alias_for_argument($token_provider, Token_Provider_Interface::class, $name);
                $container->register_alias_for_argument($token_provider, Token_Provider_Interface::class, "{$name}Provider");
                $container->register_alias_for_argument($token_provider, Token_Provider_Interface::class, "{$name}TokenProvider");
            }
            $hub_id = \sprintf('mercure.hub.%s', $name);
            $publisher_id = \sprintf('mercure.hub.%s.publisher', $name);
            $hubs[$name] = new Reference($hub_id);
            if (!$default_publisher && ($config['default_hub'] ?? $name) === $name) {
                $default_hub_name = $name;
                $default_hub_id = $hub_id;
                $default_publisher = $publisher_id;
            }
            if ($builtin_hub) {
                $container->register($hub_id, Franken_Php_Hub::class)->add_argument($hub['public_url'])->add_argument($token_factory ? new Reference($token_factory) : null)->add_tag('mercure.hub');
            } else {
                $container->register($hub_id, Hub::class)->add_argument($hub['url'])->add_argument(new Reference($token_provider))->add_argument($token_factory ? new Reference($token_factory) : null)->add_argument($hub['public_url'])->add_argument(new Reference('http_client', Container_Interface::IGNORE_ON_INVALID_REFERENCE))->add_tag('mercure.hub');
            }
            if (!$builtin_hub) {
                $container->register_alias_for_argument($hub_id, Hub_Interface::class, "{$name}Hub");
                $container->register_alias_for_argument($hub_id, Hub_Interface::class, $name);
                $publisher_definition = $container->register($publisher_id, Publisher::class)->add_argument($hub['url'])->add_argument(new Reference($token_provider))->add_argument(new Reference('http_client', Container_Interface::IGNORE_ON_INVALID_REFERENCE))->add_tag('mercure.publisher');
                $this->deprecate($publisher_definition, 'The "%service_id%" service is deprecated. You should stop using it, as it will be removed in the future, use "' . $hub_id . '" instead.');
                $this->deprecate($container->register_alias_for_argument($publisher_id, Publisher_Interface::class, "{$name}Publisher"), 'The "%alias_id%" service is deprecated. You should stop using it, as it will be removed in the future, use "' . $hub_id . '" instead.');
                $this->deprecate($container->register_alias_for_argument($publisher_id, Publisher_Interface::class, $name), 'The "%alias_id%" service is deprecated. You should stop using it, as it will be removed in the future, use "' . $hub_id . '" instead.');
            }
            $bus = $hub['bus'] ?? null;
            $attributes = null === $bus ? [] : ['bus' => $hub['bus']];
            $messenger_handler_id = \sprintf('mercure.hub.%s.message_handler', $name);
            $container->register($messenger_handler_id, Update_Handler::class)->add_argument(new Reference($hub_id))->add_tag('messenger.message_handler', $attributes);
            if ($enable_profiler) {
                if (!$builtin_hub) {
                    $traceable_publisher = $container->register("{$publisher_id}.traceable", Traceable_Publisher::class)->set_decorated_service($publisher_id)->add_argument(new Reference("{$publisher_id}.traceable.inner"))->add_argument(new Reference('debug.stopwatch'));
                    $this->deprecate($traceable_publisher, 'The "%service_id%" service is deprecated. Use "' . $hub_id . '.traceable" instead.');
                    $traceable_hubs[$name] = new Reference("{$publisher_id}.traceable");
                }
                $container->register("{$hub_id}.traceable", Traceable_Hub::class)->set_decorated_service($hub_id)->add_argument(new Reference("{$hub_id}.traceable.inner"))->add_argument(new Reference('debug.stopwatch'));
                $traceable_hubs[$name] = new Reference("{$hub_id}.traceable");
            }
            if (class_exists(Broadcaster::class)) {
                $container->register("turbo.mercure.{$name}.renderer", Turbo_Stream_Listen_Renderer::class)->add_argument(new Reference($hub_id))->add_argument(new Reference('turbo.mercure.stimulus_helper'))->add_argument(new Reference('turbo.id_accessor'))->add_argument(new Reference('twig'))->add_tag('turbo.renderer.stream_listen', ['transport' => $name]);
                if ($default_hub_name === $name && 'default' !== $name) {
                    $container->get_definition("turbo.mercure.{$name}.renderer")->add_tag('turbo.renderer.stream_listen', ['transport' => 'default']);
                }
                $container->register("turbo.mercure.{$name}.broadcaster", Broadcaster::class)->add_argument($name)->add_argument(new Reference($hub_id))->add_tag('turbo.broadcaster');
            }
        }
        if ($enable_profiler) {
            $container->register('data_collector.mercure', Mercure_Data_Collector::class)->add_argument(new Iterator_Argument($traceable_hubs))->add_tag('data_collector', ['template' => '@Mercure/Collector/mercure.html.twig', 'id' => 'mercure']);
        }
        $container->set_alias(Hub_Interface::class, $default_hub_id);
        if (null !== $default_publisher) {
            $this->deprecate($container->set_alias(Publisher::class, $default_publisher), 'The "%alias_id%" service alias is deprecated. Use "' . Hub::class . '" instead.');
            $this->deprecate($container->set_alias(Publisher_Interface::class, $default_publisher), 'The "%alias_id%" service alias is deprecated. Use "' . Hub_Interface::class . '" instead.');
        }
        $container->register(Hub_Registry::class)->add_argument(new Reference($default_hub_id))->add_argument($hubs);
        $container->register(Authorization::class)->add_argument(new Reference(Hub_Registry::class))->add_argument($config['default_cookie_lifetime']);
        $container->register(Discovery::class)->add_argument(new Reference(Hub_Registry::class));
        if (class_exists(Set_Cookie_Subscriber::class)) {
            $container->register(Set_Cookie_Subscriber::class)->add_tag('kernel.event_subscriber', ['priority' => -10]);
        }
        if (class_exists(Environment::class) && class_exists(Twig_Mercure_Extension::class)) {
            $definition = $container->register(Twig_Mercure_Extension::class)->set_arguments([new Reference(Hub_Registry::class), new Reference(Authorization::class), new Reference('request_stack')]);
            /* @phpstan-ignore function.impossibleType */
            if (is_a(Twig_Mercure_Extension::class, Abstract_Extension::class, true)) {
                $definition->add_tag('twig.extension');
            } else {
                $definition->add_tag('twig.attribute_extension')->add_tag('twig.runtime');
            }
        }
    }
    /**
     * @param Definition|Alias $definition
     */
    private function deprecate($definition, string $message): void
    {
        if (class_exists(Alias_Deprecated_Public_Services_Pass::class)) {
            $definition->set_deprecated('symfony/mercure-bundle', '0.2', $message);
        } else {
            /* @phpstan-ignore-next-line */
            $definition->set_deprecated(true, $message);
        }
    }
}