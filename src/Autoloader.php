<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator;

if (!defined('ABSPATH')) exit;

/**
 * PSR-4 compliant autoloader for the FormsWebhookIntegrator namespace.
 *
 * Registers itself with spl_autoload_register and resolves fully-qualified class
 * names under the plugin's root namespace to their corresponding file paths
 * inside the src/ directory — no Composer required.
 */
final class Autoloader
{
    /**
     * The root namespace prefix that this autoloader is responsible for.
     *
     * Any class name that does not start with this prefix is silently ignored
     * so other registered autoloaders are not disrupted.
     */
    private const NAMESPACE_PREFIX = 'FormsWebhookIntegrator\\';

    /**
     * Registers the autoloader with PHP's SPL autoload stack.
     *
     * Should be called once, immediately after this file is required.
     *
     * @return void
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    /**
     * Attempts to load the file for the given fully-qualified class name.
     *
     * The method strips the root namespace prefix, converts the remaining
     * namespace separators to directory separators, and appends '.php' to
     * build an absolute file path relative to the src/ directory.
     *
     * @param string $class Fully-qualified class name to resolve.
     *
     * @return void
     */
    public static function load(string $class): void
    {
        if (!str_starts_with($class, self::NAMESPACE_PREFIX)) {
            return;
        }

        $relativeClass = substr($class, strlen(self::NAMESPACE_PREFIX));
        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
