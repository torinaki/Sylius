<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sylius\Bundle\CoreBundle\Routing;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\Common\Util\ClassUtils;
use Symfony\Cmf\Bundle\RoutingBundle\Doctrine\DoctrineProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Bundle\LocaleBundle\Provider\LocaleProviderInterface;
use Symfony\Cmf\Bundle\RoutingBundle\Model\Route as CmfRoute;

/**
 * @author Gonzalo Vilaseca <gvilaseca@reiss.co.uk>
 * @author Paweł Jędrzejewski <pawel@sylius.org>
 */
class RouteProvider extends DoctrineProvider implements RouteProviderInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Route configuration for the object classes to search in
     *
     * @var array
     */
    protected $routeConfigs;

    /**
     * Contains an associative array of all the classes and the repositories needed in route generation
     *
     * @var ObjectRepository[]
     */
    protected $classRepositories = array();

    /**
     * @var LocaleProviderInterface
     */
    protected $localeProvider;

    /**
     * @var array
     */
    private $locales;

    /**
     * @var LocaleContextInterface
     */
    private $context;

    /**
     * @param ContainerInterface $container
     * @param ManagerRegistry    $managerRegistry
     * @param array              $routeConfigs
     */
    public function __construct(ContainerInterface $container, ManagerRegistry $managerRegistry, array $routeConfigs)
    {
        $this->container = $container;
        $this->routeConfigs = $routeConfigs;
        $this->classRepositories = array();

        parent::__construct($managerRegistry);
    }

    /**
     * {@inheritDoc}
     */
    public function getRouteByName($name, $parameters = array())
    {
        if (is_object($name)) {
            $className = ClassUtils::getClass($name);
            if (isset($this->routeConfigs[$className])) {
                return $this->createRouteFromEntity($name);
            }
        }

        foreach ($this->getRepositories() as $className => $repository) {
            $entity = $repository->findOneBy(array($this->routeConfigs[$className]['field'] => $name));
            if ($entity) {
                return $this->createRouteFromEntity($entity);
            }
        }

        throw new RouteNotFoundException("No route found for name '$name'");
    }

    /**
     * {@inheritDoc}
     */
    public function getRoutesByNames($names = null)
    {
        if (null === $names) {
            if (0 === $this->routeCollectionLimit) {
                return array();
            }

            $collection = new RouteCollection();
            foreach ($this->getRepositories() as $className => $repository) {
                $entities = $repository->findBy(array(), null, $this->routeCollectionLimit ?: null);
                foreach ($entities as $entity) {
                    $name = $this->getFieldValue($entity, $this->routeConfigs[$className]['field']);
                    $collection->add($name, $this->createRouteFromEntity($entity));
                }
            }

            return $collection;
        }

        $routes = array();
        foreach ($names as $name) {
            try {
                $routes[] = $this->getRouteByName($name);
            } catch (RouteNotFoundException $e) {
                // not found
            }
        }

        return $routes;
    }

    /**
     * {@inheritDoc}
     */
    public function getRouteCollectionForRequest(Request $request)
    {
        $path = $request->getPathInfo();
        $collection = new RouteCollection();

        if (empty($path)) {
            return $collection;
        }


        $locales = implode('|', $this->getLocales());
        $locale = null;
        foreach ($this->getRepositories() as $className => $repository) {
            if (preg_match('#^/(' . $locales . ')' . preg_quote($this->routeConfigs[$className]['prefix'], '#') . '/?(.+)/?$#', $path, $match)) {
                $locale = $match[1] ?: $this->localeProvider->getDefaultLocale();
                $value = $match[2];

                if (empty($value)) {
                    continue;
                }
                
                $entity = $repository->findOneBy(array($this->routeConfigs[$className]['field'] => $value));

                if (null === $entity) {
                    continue;
                }

                $format = null;
                if (preg_match('/.+\.([a-z]+)$/i', $value, $matches)) {
                    $format = $matches[1];
                }
                $route = $this->createRouteFromEntity($entity, $value, $locale, $format);

                $collection->add($value, $route);
            }
        }

        if (null !== $locale) {
            $request->setLocale($locale);
            $request->attributes->set('_locale', $locale);
        }

        return $collection;
    }

    /**
     * This method is called from a compiler pass
     *
     * @param string           $class
     * @param string           $id
     */
    public function addRepository($class, $id)
    {
        if (!is_string($id)) {
            throw new \InvalidArgumentException('Expected service id!');
        }

        $this->classRepositories[$class] = $id;
    }

    /**
     * Get repository services.
     *
     * @return array
     */
    private function getRepositories()
    {
        $repositories = array();

        foreach ($this->classRepositories as $class => $id) {
            $repositories[$class] = $this->container->get($id);
        }

        return $repositories;
    }

    /**
     * @param object $entity
     * @param string $fieldName
     *
     * @return string
     */
    private function getFieldValue($entity, $fieldName)
    {
        return $entity->{'get'.ucfirst($fieldName)}();
    }

    /**
     * @param object $entity
     *
     * @return Route
     */
    private function createRouteFromEntity($entity, $value = null, $locale = null, $format = null)
    {
        $className = ClassUtils::getClass($entity);
        $fieldName = $this->routeConfigs[$className]['field'];
        if ($locale === null) {
            $locale = $this->context->getLocale();
        }

        // Used for matching by translated field
        // eg:
        // If the url slug doesn't match the current's locale slug
        // the method getSlug would return the slug in current locale
        // it won't match the url and will fail
        // TODO refactor class if locale is included in url
        if (null === $value) {
            $value = $this->getFieldValue($entity, $fieldName);
        }
        $defaults = array(
            '_sylius_entity' => $entity,
            $fieldName => $value,
            '_locale' => $locale
        );
        if ($format) {
            $defaults['_format'] = $format;
        }

        $route = new CmfRoute(
            array(
                'add_format_pattern' => (bool) $format,
                'add_locale_pattern' => true,
            )
        );
        $route->setDefaults($defaults);
        $route->setStaticPrefix('/' . trim($this->routeConfigs[$className]['prefix'], '/'));
        $route->setVariablePattern('/' . $value);
        return $route;
    }

    private function getLocales()
    {
        if ($this->locales === null) {
            $this->locales = $this->localeProvider->getLocales();
        }
        return $this->locales;
    }

    public function setLocaleProvider(LocaleProviderInterface $provider)
    {
        $this->localeProvider = $provider;
    }

    public function setLocaleContext(LocaleContextInterface $context)
    {
        $this->context = $context;
    }

}
