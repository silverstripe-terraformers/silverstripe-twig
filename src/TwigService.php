<?php

namespace Terraformers\Twig;

use InvalidArgumentException;
use Throwable;
use Twig;
use const THEMES_PATH;

class TwigService
{
    /**
     * @var Twig\Environment|null
     */
    private $twig;

    /**
     * @var Twig\Loader\FilesystemLoader
     */
    private $loader;

    /**
     * @var array
     */
    private $templates = [];

    public function __construct()
    {
        $this->loader = new Twig\Loader\FilesystemLoader(THEMES_PATH . '/app/twig/');

        // @todo add debug to config
        $this->twig = new Twig\Environment(
            $this->loader,
            [
                'debug' => true,
            ]
        );

        $this->twig->addExtension(new Twig\Extension\DebugExtension());
    }

    /**
     * @param array $context
     * @param array $templates
     * @return false|string
     * @throws Throwable
     * @throws Twig\Error\LoaderError
     * @throws Twig\Error\RuntimeError
     * @throws Twig\Error\SyntaxError
     */
    public function process(array $context, array $templates)
    {
        $this->templates = is_array($templates)
            ? $templates
            : [$templates];

        return $this->getTwigTemplate()
            ->render([
                'record' => $context,
            ]);
    }

    /**
     * @return string|Twig\Template|Twig\TemplateWrapper
     * @throws Twig\Error\LoaderError
     * @throws Twig\Error\RuntimeError
     * @throws Twig\Error\SyntaxError
     * @throws InvalidArgumentException
     */
    protected function getTwigTemplate()
    {
        // Process our template stack until we find a matching twig template
        foreach ($this->templates as $value) {
            // @todo: includes not supported at this time
            if (is_array($value)) {
                continue;
            }

            $twigTemplate = sprintf('%s.twig', $value);

            // Found it! Return it
            if ($this->loader->exists($twigTemplate)) {
                return $this->twig->load($twigTemplate);
            }
        }

        throw new InvalidArgumentException(
            sprintf('Unable to find corresponding twig templates in stack %s', implode(', ', $this->templates))
        );
    }
}
