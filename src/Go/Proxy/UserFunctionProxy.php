<?php
/**
 * Go! AOP framework
 *
 * @copyright Copyright 2013, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\Proxy;

use Go\Aop\Advice;
use Go\Aop\Framework\ReflectionFunctionInvocation;
use Go\Aop\Intercept\FunctionInvocation;

use Go\Core\AspectContainer;
use ReflectionFunction;
use ReflectionParameter as Parameter;

use TokenReflection\ReflectionFileNamespace;
use TokenReflection\ReflectionParameter as ParsedParameter;
use TokenReflection\ReflectionFunction as ParsedFunction;

class UserFunctionProxy
{

    /**
     * List of advices for functions
     *
     * @var array
     */
    protected static $functionAdvices = array();

    /**
     * Indent for source code
     *
     * @var int
     */
    protected $indent = 4;

    /**
     * @var ReflectionFunction
     */
    protected $func;

    /**
     * Source code for function
     *
     * @var string
     */
    protected $functionCode = '';

    /**
     * List of advices that are used for generation of stubs
     *
     * @var array
     */
    protected $advices = array();

    /**
     * Constructs functions stub class from function Reflection
     *
     * @param ReflectionFunction $func
     * @param array $advices List of function advices
     *
     */
    public function __construct($func, array $advices = array())
    {
        $this->advices = $advices;
        $this->func = $func;
        $this->functionCode = $this->getOverriddenFunction($func, $this->getJoinpointInvocationBody($func));
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        $serialized = serialize($this->advices);

        return $this->functionCode
        // Inject advices on call
        . PHP_EOL
        . '\\' . __CLASS__ . "::injectJoinPoints('"
        . $this->func->getName() . "',"
        . " \\unserialize(" . var_export($serialized, true) . "));";
    }

    /**
     * Creates a function code from Reflection
     *
     * @param ParsedFunction $function Reflection for function
     * @param string $body Body of function
     *
     * @return string
     */
    protected function getOverriddenFunction($function, $body)
    {
        static $inMemoryCache = array();

        $functionName = $function->getShortName();

        $code = sprintf("%sfunction %s%s(%s)\n{\n%s\n}\n",
            preg_replace('/ {4}|\t/', '', $function->getDocComment()) ."\n",
            $function->returnsReference() ? '&' : '',
            $functionName,
            join(', ', $this->getParameters($function->getParameters())),
            $this->indent($body)
        );

        $inMemoryCache[$functionName] = $code;

        return $code;
    }

    /**
     * Creates definition for trait method body
     *
     * @param ReflectionFunction|ParsedFunction $function Method reflection
     *
     * @return string new method body
     */
    protected function getJoinpointInvocationBody($function)
    {
        $class   = '\\' . __CLASS__;

        $dynamicArgs   = false;
        $hasOptionals  = false;
        $hasReferences = false;

        $argValues = array_map(function ($param) use (&$dynamicArgs, &$hasOptionals, &$hasReferences) {
            /** @var $param Parameter|ParsedParameter */
            $byReference   = $param->isPassedByReference();
            $dynamicArg    = $param->name == '...';
            $dynamicArgs   = $dynamicArgs || $dynamicArg;
            $hasOptionals  = $hasOptionals || ($param->isOptional() && !$param->isDefaultValueAvailable());
            $hasReferences = $hasReferences || $byReference;

            return ($byReference ? '&' : '') . '$' . $param->name;
        }, $function->getParameters());

        if ($dynamicArgs) {
            // Remove last '...' argument
            array_pop($argValues);
        }

        $args = join(', ', $argValues);

        if ($dynamicArgs) {
            $args = $hasReferences ? "array($args) + \\func_get_args()" : '\func_get_args()';
        } elseif ($hasOptionals) {
            $args = "\\array_slice(array($args), 0, \\func_num_args())";
        } else {
            $args = "array($args)";
        }

        return <<<BODY
static \$__joinPoint = null;
if (!\$__joinPoint) {
    \$__joinPoint = {$class}::getJoinPoint('{$function->name}');
}
return \$__joinPoint->__invoke($args);
BODY;
    }

    /**
     * Indent block of code
     *
     * @param string $text Non-indented text
     *
     * @return string Indented text
     */
    protected function indent($text)
    {
        $pad   = str_pad('', $this->indent, ' ');
        $lines = array_map(function ($line) use ($pad) {
            return $pad . $line;
        }, explode("\n", $text));

        return join("\n", $lines);
    }

    /**
     * Returns list of string representation of parameters
     *
     * @param array|Parameter[]|ParsedParameter[] $parameters List of parameters
     *
     * @return array
     */
    protected function getParameters(array $parameters)
    {
        $parameterDefinitions = array();
        foreach ($parameters as $parameter) {
            if ($parameter->name == '...') {
                continue;
            }
            $parameterDefinitions[] = $this->getParameterCode($parameter);
        }

        return $parameterDefinitions;
    }

    /**
     * Return string representation of parameter
     *
     * @param Parameter|ParsedParameter $parameter Reflection parameter
     *
     * @return string
     */
    protected function getParameterCode($parameter)
    {
        $type = '';
        if ($parameter->isArray()) {
            $type = 'array';
        } elseif ($parameter->getClass()) {
            $type = '\\' . $parameter->getClass()->name;
        }
        $defaultValue = null;
        $isDefaultValueAvailable = $parameter->isDefaultValueAvailable();
        if ($isDefaultValueAvailable) {
            if ($parameter instanceof ParsedParameter) {
                $defaultValue = $parameter->getDefaultValueDefinition();
            } else {
                $defaultValue = var_export($parameter->getDefaultValue());
            }
        } elseif ($parameter->allowsNull() || $parameter->isOptional()) {
            $defaultValue = 'null';
        }
        $code = sprintf('%s%s$%s%s',
            $type ? "$type " : '',
            $parameter->isPassedByReference() ? '&' : '',
            $parameter->name,
            $defaultValue ? (" = " . $defaultValue) : ''
        );

        return $code;
    }

    /**
     * Returns a joinpoint for specific function in the namespace
     *
     * @param string $joinPointName Special joinpoint name
     * @param string $namespace Name of the namespace
     *
     * @return FunctionInvocation
     */
    public static function getJoinPoint($joinPointName)
    {
        $advices = self::$functionAdvices[$joinPointName][AspectContainer::FUNCTION_PREFIX][$joinPointName];

        return new ReflectionFunctionInvocation($joinPointName . AspectContainer::AOP_PROXIED_SUFFIX, $advices);
    }

    /**
     * Inject advices for given trait
     *
     * NB This method will be used as a callback during source code evaluation to inject joinpoints
     *
     * @param string $namespace Aop child proxy class
     * @param array|Advice[] $advices List of advices to inject into class
     *
     * @return void
     */
    public static function injectJoinPoints($namespace, array $advices = array())
    {
        self::$functionAdvices[$namespace] = $advices;
    }
}
