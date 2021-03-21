<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex\Provider;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Api\ControllerProviderInterface;
use Silex\Api\EventListenerProviderInterface;
use Silex\Application;
use Silex\ServiceControllerResolver;
use Symfony\Bridge\Twig\DataCollector\TwigDataCollector;
use Symfony\Bridge\Twig\Extension\CodeExtension;
use Symfony\Bridge\Twig\Extension\ProfilerExtension;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\SecurityBundle\DataCollector\SecurityDataCollector;
use Symfony\Bundle\WebProfilerBundle\Controller\ExceptionController;
use Symfony\Bundle\WebProfilerBundle\Controller\ExceptionPanelController;
use Symfony\Bundle\WebProfilerBundle\Controller\ProfilerController;
use Symfony\Bundle\WebProfilerBundle\Controller\RouterController;
use Symfony\Bundle\WebProfilerBundle\EventListener\WebDebugToolbarListener;
use Symfony\Bundle\WebProfilerBundle\Twig\WebProfilerExtension;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\DataCollector\FormDataCollector;
use Symfony\Component\Form\Extension\DataCollector\FormDataExtractor;
use Symfony\Component\Form\Extension\DataCollector\Proxy\ResolvedTypeFactoryDataCollectorProxy;
use Symfony\Component\Form\Extension\DataCollector\Type\DataCollectorTypeExtension;
use Symfony\Component\HttpKernel\DataCollector\AjaxDataCollector;
use Symfony\Component\HttpKernel\DataCollector\ConfigDataCollector;
use Symfony\Component\HttpKernel\DataCollector\DumpDataCollector;
use Symfony\Component\HttpKernel\DataCollector\EventDataCollector;
use Symfony\Component\HttpKernel\DataCollector\ExceptionDataCollector;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\DataCollector\MemoryDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RouterDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;
use Symfony\Component\HttpKernel\Debug\FileLinkFormatter;
use Symfony\Component\HttpKernel\Debug\TraceableEventDispatcher;
use Symfony\Component\HttpKernel\EventListener\DumpListener;
use Symfony\Component\HttpKernel\EventListener\ProfilerListener;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Translation\DataCollector\TranslationDataCollector;
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\Component\Yaml\Yaml;
use Twig\Profiler\Profile;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Symfony Web Profiler provider.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class WebProfilerServiceProvider implements ServiceProviderInterface, ControllerProviderInterface, BootableProviderInterface, EventListenerProviderInterface
{
    public function register(Container $app)
    {
        $app['profiler.mount_prefix'] = '/_profiler';
        $app->extend('dispatcher', function ($dispatcher, $app) {
            return new TraceableEventDispatcher($dispatcher, $app['stopwatch'], $app['logger']);
        });

        $baseDir = $this->getBaseDir();

        $app['data_collector.templates'] = function ($app) {
            $templates = array(
                array('config',      '@WebProfiler/Collector/config.html.twig'),
                array('request',     '@WebProfiler/Collector/request.html.twig'),
                array('exception',   '@WebProfiler/Collector/exception.html.twig'),
                array('events',      '@WebProfiler/Collector/events.html.twig'),
                array('logger',      '@WebProfiler/Collector/logger.html.twig'),
                array('time',        '@WebProfiler/Collector/time.html.twig'),
                array('router',      '@WebProfiler/Collector/router.html.twig'),
                array('memory',      '@WebProfiler/Collector/memory.html.twig'),
                array('form',        '@WebProfiler/Collector/form.html.twig'),
                array('translation', '@WebProfiler/Collector/translation.html.twig'),
            );

            if (class_exists(ProfilerExtension::class)) {
                $templates[] = array('twig', '@WebProfiler/Collector/twig.html.twig');
            }

            if (isset($app['var_dumper.cli_dumper'])) {
                $templates[] = array('dump', '@Debug/Profiler/dump.html.twig');
            }

            if (class_exists(AjaxDataCollector::class)) {
                $templates[] = array('ajax', '@WebProfiler/Collector/ajax.html.twig');
            }

            return $templates;
        };

        $app['data_collectors'] = function ($app) {
            return array(
                'config' => function ($app) { return new ConfigDataCollector(); },
                'request' => function ($app) { return new RequestDataCollector(); },
                'exception' => function ($app) { return new ExceptionDataCollector(); },
                'events' => function ($app) { return new EventDataCollector($app['dispatcher']); },
                'logger' => function ($app) { return new LoggerDataCollector($app['logger']); },
                'time' => function ($app) { return new TimeDataCollector(null, $app['stopwatch']); },
                'router' => function ($app) { return new RouterDataCollector(); },
                'memory' => function ($app) { return new MemoryDataCollector(); },
            );
        };

        if (isset($app['form.resolved_type_factory']) && class_exists('Symfony\Component\Form\Extension\DataCollector\FormDataCollector')) {
            $app['data_collectors.form.extractor'] = function () { return new FormDataExtractor(); };
            $app['data_collectors.form.collector'] = function ($app) { return new FormDataCollector($app['data_collectors.form.extractor']); };

            $app->extend('data_collectors', function ($collectors, $app) {
                $collectors['form'] = function ($app) { return $app['data_collectors.form.collector']; };

                return $collectors;
            });

            $app->extend('form.resolved_type_factory', function ($factory, $app) {
                return new ResolvedTypeFactoryDataCollectorProxy($factory, $app['data_collectors']['form']($app));
            });

            $app->extend('form.type.extensions', function ($extensions, $app) {
                $extensions[] = new DataCollectorTypeExtension($app['data_collectors']['form']($app));

                return $extensions;
            });
        }

        if (class_exists(ProfilerExtension::class)) {
            $app->extend('data_collectors', function ($collectors, $app) {
                $collectors['twig'] = function ($app) {
                    return new TwigDataCollector($app['twig.profiler.profile'], $app['twig']);
                };

                return $collectors;
            });

            $app['twig.profiler.profile'] = function () {
                return new Profile();
            };
        }

        if (isset($app['var_dumper.cli_dumper'])) {
            $app['var_dumper.dump_listener'] = function ($app) {
                return new DumpListener($app['var_dumper.cloner'], $app['var_dumper.data_collector']);
            };

            $app->extend('data_collectors', function ($collectors, $app) {
                $collectors['dump'] = function ($app) {
                    $dumper = null === $app['var_dumper.dump_destination'] ? null : $app['var_dumper.cli_dumper'];

                    return $app['var_dumper.data_collector'] = new DumpDataCollector($app['stopwatch'], null, $app['charset'], $app['request_stack'], $dumper);
                };

                return $collectors;
            });
        }

        if (class_exists(AjaxDataCollector::class)) {
            $app->extend('data_collectors', function ($collectors, $app) {
                $collectors['ajax'] = function ($app) {
                    return new AjaxDataCollector();
                };

                return $collectors;
            });
        }

        if (isset($app['security.token_storage']) && class_exists(SecurityDataCollector::class)) {
            $app->extend('data_collectors', function ($collectors, $app) {
                $collectors['security'] = function ($app) {
                    $roleHierarchy = new RoleHierarchy($app['security.role_hierarchy']);
                    $logoutUrlGenerator = new LogoutUrlGenerator($app['request_stack'], $app['url_generator'], $app['security.token_storage']);

                    return new SecurityDataCollector($app['security.token_storage'], $roleHierarchy, $logoutUrlGenerator);
                };

                return $collectors;
            });

            $app->extend('data_collector.templates', function ($templates) {
                $templates[] = array('security', '@Security/Collector/security.html.twig');

                return $templates;
            });

            $app->extend('twig.loader.filesystem', function ($loader, $app) {
//                    $loader->addPath($app['profiler.templates_path.security'], 'Security');

                return $loader;
            });

            $app['twig'] = $app->extend('twig', function ($twig, $app) {
                $twig->addFilter(new TwigFilter('yaml_encode', function (array $var) {
                    return Yaml::dump($var);
                }));

                $twig->addFunction(new TwigFunction('yaml_encode', function (array $var) {
                    return Yaml::dump($var);
                }));

                return $twig;
            });
        }

        if (isset($app['translator']) && class_exists(TranslationDataCollector::class)) {
            $app->extend('data_collectors', function ($collectors, $app) {
                $collectors['translation'] = function ($app) {
                    return new TranslationDataCollector($app['translator']);
                };

                return $collectors;
            });

            $app->extend('translator', function ($translator, $app) {
                return new DataCollectorTranslator($translator);
            });
        }

        $app['web_profiler.controller.profiler'] = function ($app) use ($baseDir) {
            return new ProfilerController($app['url_generator'], $app['profiler'], $app['twig'], $app['data_collector.templates'], null, $baseDir);
        };

        $app['web_profiler.controller.router'] = function ($app) {
            return new RouterController($app['profiler'], $app['twig'], $app['request_matcher'] ?? null, $app['routes']);
        };

        $app['web_profiler.controller.exception'] = function ($app) {
            return new ExceptionPanelController($app['twig'], $app['profiler']);
        };

        $app['web_profiler.toolbar.listener'] = function ($app) {
            $mode = $app['web_profiler.debug_toolbar.enable'] ? WebDebugToolbarListener::ENABLED : WebDebugToolbarListener::DISABLED;

            return new WebDebugToolbarListener($app['twig'], $app['web_profiler.debug_toolbar.intercept_redirects'], $mode, $app['url_generator']);
        };

        $app['profiler'] = function ($app) {
            $profiler = new Profiler($app['profiler.storage'], $app['logger']);

            foreach ($app['data_collectors'] as $collector) {
                $profiler->add($collector($app));
            }

            return $profiler;
        };

        $app['profiler.storage'] = function ($app) {
            return new FileProfilerStorage('file:'.$app['profiler.cache_dir']);
        };

        $app['profiler.request_matcher'] = null;
        $app['profiler.only_exceptions'] = false;
        $app['profiler.only_master_requests'] = false;
        $app['web_profiler.debug_toolbar.enable'] = true;
        $app['web_profiler.debug_toolbar.position'] = 'bottom';
        $app['web_profiler.debug_toolbar.intercept_redirects'] = false;

        $app['profiler.listener'] = function ($app) {
            if (Kernel::VERSION_ID >= 20800) {
                return new ProfilerListener($app['profiler'], $app['request_stack'], $app['profiler.request_matcher'], $app['profiler.only_exceptions'], $app['profiler.only_master_requests']);
            } else {
                return new ProfilerListener($app['profiler'], $app['profiler.request_matcher'], $app['profiler.only_exceptions'], $app['profiler.only_master_requests'], $app['request_stack']);
            }
        };

        $app['stopwatch'] = function () {
            return new Stopwatch();
        };

        $app['code.file_link_format'] = null;

        $app->extend('twig', function ($twig, $app) use ($baseDir) {
            if (class_exists(FileLinkFormatter::class)) {
                $app['code.file_link_format'] = new FileLinkFormatter($app['code.file_link_format'], $app['request_stack'], $baseDir, '/_profiler/open?file=%f&line=%l#line%l');
            }

            $twig->addExtension(new CodeExtension($app['code.file_link_format'], '', $app['charset']));

            if (class_exists(WebProfilerExtension::class)) {
                $twig->addExtension(new WebProfilerExtension());
            }

            if (class_exists(ProfilerExtension::class)) {
                $twig->addExtension(new ProfilerExtension($app['twig.profiler.profile'], $app['stopwatch']));
            }

            return $twig;
        });

        $app->extend('twig.loader.filesystem', function ($loader, $app) {
            $loader->addPath($app['profiler.templates_path'], 'WebProfiler');
            $loader->addPath($this->getBaseDir() . '/templates/bundles/TwigBundle', 'Twig');
            $loader->addPath($this->getBaseDir() . '/templates/bundles/SecurityBundle', 'Security');
            $loader->addPath($this->getBaseDir() . '/templates/bundles/DebugBundle', 'Debug');

            return $loader;
        });

        $app['profiler.templates_path'] = function () {
            $r = new \ReflectionClass(WebDebugToolbarListener::class);

            return dirname($r->getFileName(), 2) . '/Resources/views';
        };
    }

    public function connect(Application $app)
    {
        if (!$app['resolver'] instanceof ServiceControllerResolver) {
            // using RuntimeException crashes PHP?!
            throw new \LogicException('You must enable the ServiceController service provider to be able to use the WebProfiler.');
        }

        $controllers = $app['controllers_factory'];

        $controllers->get('/router/{token}', 'web_profiler.controller.router:panelAction')->bind('_profiler_router');
        $controllers->get('/exception/{token}.css', 'web_profiler.controller.exception:cssAction')->bind('_profiler_exception_css');
        $controllers->get('/exception/{token}', 'web_profiler.controller.exception:showAction')->bind('_profiler_exception');
        $controllers->get('/search', 'web_profiler.controller.profiler:searchAction')->bind('_profiler_search');
        $controllers->get('/search_bar', 'web_profiler.controller.profiler:searchBarAction')->bind('_profiler_search_bar');
        $controllers->get('/purge', 'web_profiler.controller.profiler:purgeAction')->bind('_profiler_purge');
        $controllers->get('/info/{about}', 'web_profiler.controller.profiler:infoAction')->bind('_profiler_info');
        $controllers->get('/phpinfo', 'web_profiler.controller.profiler:phpinfoAction')->bind('_profiler_phpinfo');
        $controllers->get('/open', 'web_profiler.controller.profiler:openAction')->bind('_profiler_open_file');
        $controllers->get('/{token}/search/results', 'web_profiler.controller.profiler:searchResultsAction')->bind('_profiler_search_results');
        $controllers->get('/{token}', 'web_profiler.controller.profiler:panelAction')->bind('_profiler');
        $controllers->get('/wdt/{token}', 'web_profiler.controller.profiler:toolbarAction')->bind('_wdt');
        $controllers->get('/', 'web_profiler.controller.profiler:homeAction')->bind('_profiler_home');

        return $controllers;
    }

    public function boot(Application $app)
    {
        $app->mount($app['profiler.mount_prefix'], $this->connect($app));
    }

    public function subscribe(Container $app, EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addSubscriber($app['profiler.listener']);

        if ($app['web_profiler.debug_toolbar.enable']) {
            $dispatcher->addSubscriber($app['web_profiler.toolbar.listener']);
        }

        $dispatcher->addSubscriber($app['profiler']->get('request'));

        if (isset($app['var_dumper.data_collector'])) {
            $app['var_dumper.dump_listener']->configure();
        }
    }

    private function getBaseDir()
    {
        $baseDir = array();
        $rootDir = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $rootDir = end($rootDir)['file'];
        $rootDir = explode(DIRECTORY_SEPARATOR, realpath($rootDir) ?: $rootDir);
        $providerDir = explode(DIRECTORY_SEPARATOR, __DIR__);
        for ($i = 0; isset($rootDir[$i], $providerDir[$i]); ++$i) {
            if ($rootDir[$i] !== $providerDir[$i]) {
                break;
            }
            $baseDir[] = $rootDir[$i];
        }

        return implode(DIRECTORY_SEPARATOR, $baseDir);
    }
}
