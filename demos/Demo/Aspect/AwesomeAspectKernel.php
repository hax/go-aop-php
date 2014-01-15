<?php
/**
 * Go! OOP&AOP PHP framework
 *
 * @copyright     Copyright 2012, Lissachenko Alexander <lisachenko.it@gmail.com>
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

namespace Demo\Aspect;

use Go\Aop\Framework\MethodBeforeInterceptor;
use Go\Aop\Framework\TraitIntroductionInfo;
use Go\Aop\Intercept\MethodInvocation;
use Go\Aop\Pointcut\TrueMethodPointcut;
use Go\Aop\Pointcut\TruePointcut;
use Go\Aop\Support\DeclareParentsAdvisor;
use Go\Aop\Support\DefaultPointcutAdvisor;
use Go\Aop\Support\TruePointFilter;
use Go\Core\AspectKernel;
use Go\Core\AspectContainer;

/**
 * Awesome Aspect Kernel class
 */
class AwesomeAspectKernel extends AspectKernel
{
    /**
     * Configure an AspectContainer with advisors, aspects and pointcuts
     *
     * @param AspectContainer $container
     *
     * @return void
     */
    protected function configureAop(AspectContainer $container)
    {
        $container->registerAdvisor(
            new DefaultPointcutAdvisor(
                new TrueMethodPointcut(),
                new MethodBeforeInterceptor(function (MethodInvocation $invocation) {
                    echo "Hello, ", $invocation->getMethod()->name, PHP_EOL;
                })
            ),
            'test'
        );

        $container->registerAdvisor(
            new DeclareParentsAdvisor(
                TruePointFilter::getInstance(),
                new TraitIntroductionInfo(
                    'Serializable', 'Demo\Aspect\Introduce\SerializableImpl'
                )
            ),
            'introduce'
        );
//        $container->registerAspect(new DebugAspect());
//        $container->registerAspect(new FluentInterfaceAspect());
//        $container->registerAspect(new HealthyLiveAspect());
    }
}
