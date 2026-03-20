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

use Symfony\Component\Config\Definition\Builder\Tree_Builder;
use Symfony\Component\Config\Definition\Configuration_Interface;
use Symfony\Component\Mercure\Franken_Php_Hub;
/**
 * MercureExtension configuration structure.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class Configuration implements Configuration_Interface
{
    public function get_config_tree_builder(): Tree_Builder
    {
        $builtin_publish = class_exists(Franken_Php_Hub::class) && \function_exists('mercure_publish');
        $tree_builder = new Tree_Builder('mercure');
        $root_node = $tree_builder->get_root_node();
        $url_node = $root_node->fix_xml_config('hub')->children()->array_node('hubs')->use_attribute_as_key('name')->normalize_keys(false)->array_prototype()->children()->scalar_node('url')->info('URL of the hub\'s publish endpoint')->example('https://demo.mercure.rocks/.well-known/mercure');
        if ($builtin_publish) {
            $url_node->default_null();
        }
        $public_url_node = $url_node->end()->scalar_node('public_url')->info('URL of the hub\'s public endpoint')->example('https://demo.mercure.rocks/.well-known/mercure');
        if (!$builtin_publish) {
            $public_url_node->default_null();
        }
        $public_url_node->end()->array_node('jwt')->before_normalization()->if_string()->then(static fn(string $token): array => ['value' => $token])->end()->info('JSON Web Token configuration.')->children()->scalar_node('value')->info('JSON Web Token to use to publish to this hub.')->end()->scalar_node('provider')->info('The ID of a service to call to provide the JSON Web Token.')->end()->scalar_node('factory')->info('The ID of a service to call to create the JSON Web Token.')->end()->array_node('publish')->before_normalization()->cast_to_array()->end()->scalar_prototype()->end()->info('A list of topics to allow publishing to when using the given factory to generate the JWT.')->end()->array_node('subscribe')->before_normalization()->cast_to_array()->end()->scalar_prototype()->end()->info('A list of topics to allow subscribing to when using the given factory to generate the JWT.')->end()->scalar_node('secret')->info('The JWT Secret to use.')->example('!ChangeMe!')->end()->scalar_node('passphrase')->info('The JWT secret passphrase.')->default_value('')->end()->scalar_node('algorithm')->info('The algorithm to use to sign the JWT')->default_value('hmac.sha256')->end()->end()->end()->scalar_node('jwt_provider')->info('The ID of a service to call to generate the JSON Web Token.')->set_deprecated('symfony/mercure-bundle', '0.3', 'The child node "%node%" at path "%path%" is deprecated, use "jwt.provider" instead.')->end()->scalar_node('bus')->info('Name of the Messenger bus where the handler for this hub must be registered. Default to the default bus if Messenger is enabled.')->end()->end()->validate()->if_true(fn($v) => isset($v['jwt'], $v['jwt_provider']))->then_invalid('"jwt" and "jwt_provider" cannot be used together.')->end()->validate()->if_true(fn($v) => isset($v['url']) && !isset($v['jwt']) && !isset($v['jwt_provider']))->then_invalid('You must specify at least one of "jwt", and "jwt_provider".')->end()->validate()->if_true(fn($v) => isset($v['jwt']['value'], $v['jwt']['provider']))->then_invalid('"jwt.value" and "jwt.provider" cannot be used together.')->end()->validate()->if_true(fn($v) => isset($v['jwt']) && !isset($v['jwt']['value']) && !isset($v['jwt']['provider']) && !isset($v['jwt']['factory']) && !isset($v['jwt']['secret']))->then_invalid('You must specify at least one of "jwt.value", "jwt.provider", "jwt.factory", and "jwt.secret".')->end()->end()->end()->scalar_node('default_hub')->end()->integer_node('default_cookie_lifetime')->default_null()->info('Default lifetime of the cookie containing the JWT, in seconds. Defaults to the value of "framework.session.cookie_lifetime".')->end()->boolean_node('enable_profiler')->info('Enable Symfony Web Profiler integration.')->set_deprecated('symfony/mercure-bundle', '0.3')->end()->end()->end();
        return $tree_builder;
    }
}