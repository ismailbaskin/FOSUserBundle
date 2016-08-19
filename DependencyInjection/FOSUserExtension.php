<?php

/*
 * This file is part of the FOSUserBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\UserBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Config\FileLocator;

class FOSUserExtension extends Extension
{
    private static $doctrineDrivers = array(
        'orm' => array(
            'registry' => 'doctrine',
            'tag' => 'doctrine.event_subscriber',
        ),
    );

    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();
        $configuration = new Configuration();

        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        $loader->load('doctrine.xml');
        $container->setAlias('fos_user.doctrine_registry', new Alias(self::$doctrineDrivers['orm']['registry'], false));
        $container->setParameter($this->getAlias() . '.backend_type_orm', true);

        // Configure the factory for both Symfony 2.3 and 2.6+
        if (isset(self::$doctrineDrivers['orm'])) {
            $definition = $container->getDefinition('fos_user.object_manager');
            if (method_exists($definition, 'setFactory')) {
                $definition->setFactory(array(new Reference('fos_user.doctrine_registry'), 'getManager'));
            } else {
                $definition->setFactoryService('fos_user.doctrine_registry');
                $definition->setFactoryMethod('getManager');
            }
        }

        foreach (array('validator', 'security', 'util', 'mailer', 'listeners') as $basename) {
            $loader->load(sprintf('%s.xml', $basename));
        }

        $tokenStorageReference = new Reference('security.token_storage');

        $container
            ->getDefinition('fos_user.security.login_manager')
            ->replaceArgument(0, $tokenStorageReference)
        ;

        if ($config['use_flash_notifications']) {
            $loader->load('flash_notifications.xml');
        }

        $container->setAlias('fos_user.mailer', $config['service']['mailer']);
        $container->setAlias('fos_user.util.email_canonicalizer', $config['service']['email_canonicalizer']);
        $container->setAlias('fos_user.util.username_canonicalizer', $config['service']['username_canonicalizer']);
        $container->setAlias('fos_user.util.token_generator', $config['service']['token_generator']);
        $container->setAlias('fos_user.user_manager', $config['service']['user_manager']);

        if ($config['use_listener'] && isset(self::$doctrineDrivers['orm'])) {
            $listenerDefinition = $container->getDefinition('fos_user.user_listener');
            $listenerDefinition->addTag(self::$doctrineDrivers['orm']['tag']);
            if (isset(self::$doctrineDrivers['orm']['listener_class'])) {
                $listenerDefinition->setClass(self::$doctrineDrivers['orm']['listener_class']);
            }
        }

        if ($config['use_username_form_type']) {
            $loader->load('username_form_type.xml');
        }

        $this->remapParametersNamespaces($config, $container, array(
            ''          => array(
                'firewall_name' => 'fos_user.firewall_name',
                'model_manager_name' => 'fos_user.model_manager_name',
                'user_class' => 'fos_user.model.user.class',
            ),
        ));

        if (!empty($config['profile'])) {
            $this->loadProfile($config['profile'], $container, $loader);
        }

        if (!empty($config['registration'])) {
            $this->loadRegistration($config['registration'], $container, $loader, $config['from_email']);
        }

        if (!empty($config['change_password'])) {
            $this->loadChangePassword($config['change_password'], $container, $loader);
        }

        if (!empty($config['resetting'])) {
            $this->loadResetting($config['resetting'], $container, $loader, $config['from_email']);
        }
    }

    private function loadProfile(array $config, ContainerBuilder $container, XmlFileLoader $loader)
    {
        $loader->load('profile.xml');

        $this->remapParametersNamespaces($config, $container, array(
            'form' => 'fos_user.profile.form.%s',
        ));
    }

    private function loadRegistration(array $config, ContainerBuilder $container, XmlFileLoader $loader, array $fromEmail)
    {
        $loader->load('registration.xml');

        if ($config['confirmation']['enabled']) {
            $loader->load('email_confirmation.xml');
        }

        if (isset($config['confirmation']['from_email'])) {
            // overwrite the global one
            $fromEmail = $config['confirmation']['from_email'];
            unset($config['confirmation']['from_email']);
        }
        $container->setParameter('fos_user.registration.confirmation.from_email', array($fromEmail['address'] => $fromEmail['sender_name']));

        $this->remapParametersNamespaces($config, $container, array(
            'confirmation' => 'fos_user.registration.confirmation.%s',
            'form' => 'fos_user.registration.form.%s',
        ));
    }

    private function loadChangePassword(array $config, ContainerBuilder $container, XmlFileLoader $loader)
    {
        $loader->load('change_password.xml');

        $this->remapParametersNamespaces($config, $container, array(
            'form' => 'fos_user.change_password.form.%s',
        ));
    }

    private function loadResetting(array $config, ContainerBuilder $container, XmlFileLoader $loader, array $fromEmail)
    {
        $loader->load('resetting.xml');

        if (isset($config['email']['from_email'])) {
            // overwrite the global one
            $fromEmail = $config['email']['from_email'];
            unset($config['email']['from_email']);
        }
        $container->setParameter('fos_user.resetting.email.from_email', array($fromEmail['address'] => $fromEmail['sender_name']));

        $this->remapParametersNamespaces($config, $container, array(
            '' => array (
                'token_ttl' => 'fos_user.resetting.token_ttl',
            ),
            'email' => 'fos_user.resetting.email.%s',
            'form' => 'fos_user.resetting.form.%s',
        ));
    }

    protected function remapParameters(array $config, ContainerBuilder $container, array $map)
    {
        foreach ($map as $name => $paramName) {
            if (array_key_exists($name, $config)) {
                $container->setParameter($paramName, $config[$name]);
            }
        }
    }

    protected function remapParametersNamespaces(array $config, ContainerBuilder $container, array $namespaces)
    {
        foreach ($namespaces as $ns => $map) {
            if ($ns) {
                if (!array_key_exists($ns, $config)) {
                    continue;
                }
                $namespaceConfig = $config[$ns];
            } else {
                $namespaceConfig = $config;
            }
            if (is_array($map)) {
                $this->remapParameters($namespaceConfig, $container, $map);
            } else {
                foreach ($namespaceConfig as $name => $value) {
                    $container->setParameter(sprintf($map, $name), $value);
                }
            }
        }
    }

    public function getNamespace()
    {
        return 'http://friendsofsymfony.github.io/schema/dic/user';
    }
}
