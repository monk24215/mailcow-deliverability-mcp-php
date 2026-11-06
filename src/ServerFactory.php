<?php

declare(strict_types=1);

namespace MailcowDeliverability;

use MailcowDeliverability\Mcp\DeliverabilityTools;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

final class ServerFactory
{
    public const NAME = 'mailcow-deliverability';

    public const VERSION = '0.1.0';

    public static function builder(Config $config, ?LoggerInterface $logger = null): Builder
    {
        $tools = new DeliverabilityTools(DeliveryLog::fromConfig($config));
        $readOnly = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );

        $builder = Server::builder()
            ->setServerInfo(self::NAME, self::VERSION, 'Per-provider sending reputation from Postfix delivery logs')
            ->setInstructions(
                'Reads a Postfix delivery log and reports sending reputation per receiving mailbox provider. '
                .'Start with provider_reputation, then use top_deferral_reasons, bounce_breakdown and recent_rejections to see why. '
                .'Judge providers on unique recipients, not attempts: Postfix retries deferred addresses many times. '
                .'Windows end at the newest log entry by default (anchor "latest"). log_source_info shows which log is loaded.'
            )
            ->addTool([$tools, 'providerReputation'], 'provider_reputation', 'Provider reputation', annotations: $readOnly)
            ->addTool([$tools, 'topDeferralReasons'], 'top_deferral_reasons', 'Top deferral reasons', annotations: $readOnly)
            ->addTool([$tools, 'bounceBreakdown'], 'bounce_breakdown', 'Bounce breakdown by DSN', annotations: $readOnly)
            ->addTool([$tools, 'recentRejections'], 'recent_rejections', 'Recent rejections', annotations: $readOnly)
            ->addTool([$tools, 'logSourceInfo'], 'log_source_info', 'Log source info', annotations: $readOnly);

        if (null !== $logger) {
            $builder->setLogger($logger);
        }

        return $builder;
    }

    public static function create(Config $config, ?LoggerInterface $logger = null): Server
    {
        return self::builder($config, $logger)->build();
    }
}
