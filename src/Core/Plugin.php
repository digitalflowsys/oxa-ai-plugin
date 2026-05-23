<?php
/**
 * Plugin orchestrator.
 *
 * Wires the DI container, registers REST routes, admin pages, and
 * exposes a singleton accessor used by REST controllers and admin.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Core;

use OxaAi\Admin\Admin;
use OxaAi\Authoring\BrandManager;
use OxaAi\Authoring\ComponentAuthor;
use OxaAi\Generation\PageGenerator;
use OxaAi\Generation\PromptBuilder;
use OxaAi\Mcp\Auth\BearerAuth;
use OxaAi\Mcp\McpServer;
use OxaAi\Mcp\ToolRegistry;
use OxaAi\Mcp\Tools\AddSectionTool;
use OxaAi\Mcp\Tools\ComposePageTool;
use OxaAi\Mcp\Tools\CreateComponentTool;
use OxaAi\Mcp\Tools\CreatePageTool;
use OxaAi\Mcp\Tools\DeleteSectionTool;
use OxaAi\Mcp\Tools\GeneratePageTool;
use OxaAi\Mcp\Tools\GetComponentSchemaTool;
use OxaAi\Mcp\Tools\GetComponentSourceTool;
use OxaAi\Mcp\Tools\GetPageTool;
use OxaAi\Mcp\Tools\ListComponentsTool;
use OxaAi\Mcp\Tools\ListPagesTool;
use OxaAi\Mcp\Tools\SetBrandTool;
use OxaAi\Mcp\Tools\UpdateComponentTool;
use OxaAi\Mcp\Tools\UpdateSectionTool;
use OxaAi\Providers\ClaudeProvider;
use OxaAi\Providers\MockProvider;
use OxaAi\Providers\OpenAIProvider;
use OxaAi\Providers\ProviderInterface;
use OxaAi\Providers\ProviderRegistry;
use OxaAi\Rendering\GutenbergComposer;
use OxaAi\Rendering\LayoutReader;
use OxaAi\Rendering\PageWriter;
use OxaAi\Rest\RestController;
use OxaAi\Schema\SchemaRegistry;
use OxaAi\Schema\SchemaValidator;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Container $container = null;

    public static function boot(): void
    {
        if (self::$container !== null) {
            return;
        }
        self::$container = self::buildContainer();

        add_action('rest_api_init', static function (): void {
            /** @var RestController $rest */
            $rest = self::$container->get(RestController::class);
            $rest->register();
        });

        if (is_admin()) {
            /** @var Admin $admin */
            $admin = self::$container->get(Admin::class);
            $admin->register();
        }
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            self::$container = self::buildContainer();
        }
        return self::$container;
    }

    public static function onActivate(): void
    {
        if (!get_option(Settings::OPTION_CONFIG)) {
            (new Settings())->saveConfig([
                'provider'    => 'mock',
                'model'       => '',
                'max_tokens'  => 4096,
                'temperature' => 0.4,
            ]);
        }
    }

    public static function onDeactivate(): void
    {
        // Intentionally minimal: do not destroy user content or settings on deactivate.
    }

    private static function buildContainer(): Container
    {
        $c = new Container();

        $c->set(Logger::class,          static fn(): object => new Logger());
        $c->set(Settings::class,        static fn(): object => new Settings());

        $c->set(SchemaRegistry::class,  static fn(): object => new SchemaRegistry());
        $c->set(SchemaValidator::class, static fn(Container $c): object => new SchemaValidator($c->get(SchemaRegistry::class)));

        $c->set(ProviderRegistry::class, static function (Container $c): object {
            /** @var Settings $settings */
            $settings = $c->get(Settings::class);
            /** @var Logger $logger */
            $logger   = $c->get(Logger::class);

            $registry = new ProviderRegistry();
            $registry->add(new MockProvider());
            $registry->add(new OpenAIProvider($settings, $logger));
            $registry->add(new ClaudeProvider($settings, $logger));

            /**
             * Allow third parties to register additional providers.
             *
             * @param ProviderRegistry $registry
             */
            do_action('oxa_ai_register_providers', $registry);
            return $registry;
        });

        $c->set(PromptBuilder::class, static fn(Container $c): object =>
            new PromptBuilder($c->get(SchemaRegistry::class)));

        $c->set(GutenbergComposer::class, static fn(): object => new GutenbergComposer());

        $c->set(PageGenerator::class, static fn(Container $c): object => new PageGenerator(
            $c->get(ProviderRegistry::class),
            $c->get(SchemaValidator::class),
            $c->get(PromptBuilder::class),
            $c->get(GutenbergComposer::class),
            $c->get(Settings::class),
            $c->get(Logger::class)
        ));

        // -------- MCP plumbing --------
        $c->set(BearerAuth::class,        static fn(): object => new BearerAuth());
        $c->set(LayoutReader::class,      static fn(): object => new LayoutReader());
        $c->set(PageWriter::class,        static fn(Container $c): object => new PageWriter($c->get(GutenbergComposer::class)));

        // -------- Authoring (brand + component file writes) --------
        $c->set(BrandManager::class,    static fn(): object => new BrandManager());
        $c->set(ComponentAuthor::class, static fn(Container $c): object => new ComponentAuthor(
            $c->get(SchemaRegistry::class),
            $c->get(\OxaAi\Core\Logger::class)
        ));

        $c->set(ToolRegistry::class, static function (Container $c): object {
            $registry = new ToolRegistry();

            // 1. Discovery — Claude calls these first to learn the system.
            $registry->add(new ListComponentsTool($c->get(SchemaRegistry::class)));
            $registry->add(new GetComponentSchemaTool($c->get(SchemaRegistry::class)));
            $registry->add(new GetComponentSourceTool($c->get(ComponentAuthor::class)));

            // 2. Authoring — define the brand and design system.
            $registry->add(new SetBrandTool($c->get(BrandManager::class)));
            $registry->add(new CreateComponentTool($c->get(ComponentAuthor::class)));
            $registry->add(new UpdateComponentTool($c->get(ComponentAuthor::class)));

            // 3. Page composition.
            $registry->add(new ComposePageTool(
                $c->get(SchemaValidator::class),
                $c->get(GutenbergComposer::class)
            ));
            $registry->add(new GeneratePageTool($c->get(PageGenerator::class)));
            $registry->add(new CreatePageTool($c->get(PageGenerator::class)));

            // 4. Page mutations.
            $registry->add(new ListPagesTool($c->get(LayoutReader::class)));
            $registry->add(new GetPageTool($c->get(LayoutReader::class)));
            $registry->add(new UpdateSectionTool(
                $c->get(LayoutReader::class),
                $c->get(PageWriter::class),
                $c->get(SchemaValidator::class),
            ));
            $registry->add(new AddSectionTool(
                $c->get(LayoutReader::class),
                $c->get(PageWriter::class),
                $c->get(SchemaValidator::class),
            ));
            $registry->add(new DeleteSectionTool(
                $c->get(LayoutReader::class),
                $c->get(PageWriter::class),
            ));

            do_action('oxa_ai_register_mcp_tools', $registry, $c);

            return $registry;
        });

        $c->set(McpServer::class, static fn(Container $c): object => new McpServer(
            $c->get(ToolRegistry::class),
            $c->get(\OxaAi\Core\Logger::class)
        ));

        $c->set(RestController::class, static fn(Container $c): object => new RestController($c));
        $c->set(Admin::class,          static fn(Container $c): object => new Admin($c));

        return $c;
    }
}
