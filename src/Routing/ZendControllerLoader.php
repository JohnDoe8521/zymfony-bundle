<?php declare(strict_types=1);

namespace SAA\ZymfonyBundle\Routing;

use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class ZendControllerLoader extends Loader
{
    /**
     * @var FileLocatorInterface
     */
    private $fileLocator;

    /**
     * @var \Zend_Filter_Word_CamelCaseToDash
     */
    private $camelCaseToDashFilter;

    public function __construct(FileLocatorInterface $fileLocator)
    {
        $this->fileLocator = $fileLocator;
        $this->camelCaseToDashFilter = new \Zend_Filter_Word_CamelCaseToDash();
    }

    /**
     * @param string $resource
     * @param string $type
     * @return RouteCollection
     * @throws \ReflectionException
     */
    public function load($resource, $type = null)
    {
        $routeCollection = new RouteCollection();

        $directoryPath = $this->fileLocator->locate($resource);

        foreach ($this->getControllerIterator($directoryPath) as $path => $info) {
            $class = $this->findClass($path);

            list($controllerRoutePart) = explode('Controller', $class);
            $controllerRoutePart = strtolower($this->camelCaseToDashFilter->filter($controllerRoutePart));

            $reflector = new \ReflectionClass($class);
            $methods = $reflector->getMethods();

            foreach ($methods as $method) {
                if (strpos($method->getName(), 'Action') === false) {
                    continue;
                }

                $this->addRouteFromMethod($routeCollection, $method, $controllerRoutePart);
            }
        }

        return $routeCollection;
    }

    /**
     * @param mixed
     * @param string|null
     * @return bool
     */
    public function supports($resource, $type = null)
    {
        return $type === 'zend' && is_string($resource) && is_dir($this->fileLocator->locate($resource));
    }

    private function getControllerIterator(string $path)
    {
        $resource =  new GlobResource($path, '*Controller.php', false);

        yield from $resource;
    }

    /**
     * From Symfony\Component\Routing\Loader\AnnotationFileLoader
     *
     * Returns the full class name for the first class in the file.
     *
     * @param string $file A PHP file path
     *
     * @return string|false Full class name if found, false otherwise
     */
    private function findClass($file)
    {
        $class = false;
        $namespace = false;
        $tokens = token_get_all(file_get_contents($file));

        if (1 === \count($tokens) && T_INLINE_HTML === $tokens[0][0]) {
            throw new \InvalidArgumentException(sprintf('The file "%s" does not contain PHP code. Did you forgot to add the "<?php" start tag at the beginning of the file?', $file));
        }

        for ($i = 0; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if (!isset($token[1])) {
                continue;
            }

            if (true === $class && T_STRING === $token[0]) {
                return $namespace.'\\'.$token[1];
            }

            if (true === $namespace && T_STRING === $token[0]) {
                $namespace = $token[1];
                while (isset($tokens[++$i][1]) && \in_array($tokens[$i][0], array(T_NS_SEPARATOR, T_STRING))) {
                    $namespace .= $tokens[$i][1];
                }
                $token = $tokens[$i];
            }

            if (T_CLASS === $token[0]) {
                // Skip usage of ::class constant and anonymous classes
                $skipClassToken = false;
                for ($j = $i - 1; $j > 0; --$j) {
                    if (!isset($tokens[$j][1])) {
                        break;
                    }

                    if (T_DOUBLE_COLON === $tokens[$j][0] || T_NEW === $tokens[$j][0]) {
                        $skipClassToken = true;
                        break;
                    } elseif (!\in_array($tokens[$j][0], array(T_WHITESPACE, T_DOC_COMMENT, T_COMMENT))) {
                        break;
                    }
                }

                if (!$skipClassToken) {
                    $class = true;
                }
            }

            if (T_NAMESPACE === $token[0]) {
                $namespace = true;
            }
        }

        return false;
    }

    /**
     * @param RouteCollection $routeCollection
     * @param \ReflectionMethod $method
     * @param string $controllerRoutePart
     */
    private function addRouteFromMethod(
        RouteCollection $routeCollection,
        \ReflectionMethod $method,
        string $controllerRoutePart
    ) {
        $methodName = $method->getName();

        $methodRoutePart = str_replace('Action', '', $methodName);
        $methodRoutePart = str_replace(
            ' ',
            '-',
            trim(strtolower($this->camelCaseToDashFilter->filter($methodRoutePart)))
        );

        $route = new Route(
            '/' . $controllerRoutePart . '/' . $methodRoutePart,
            [
                '_controller' => $method->getDeclaringClass()->getName() . '::' . $methodName,
            ]
        );

        $routeCollection->add('zend_' . $controllerRoutePart . $methodRoutePart, $route);
    }
}
