<?php

declare(strict_types=1);

namespace Supertab\Connect\Bot;

use Supertab\Connect\Http\RequestContext;

/**
 * Default bot detection using multiple signals.
 *
 * Checks User-Agent patterns, headless browser indicators, and missing headers.
 * Port of the TypeScript SDK's defaultBotDetector (bots.ts).
 */
final class DefaultBotDetector implements BotDetectorInterface
{
    /** @var list<string> */
    private const BOT_LIST = [
        'chatgpt-user',
        'perplexitybot',
        'gptbot',
        'anthropic-ai',
        'ccbot',
        'claude-web',
        'claudebot',
        'cohere-ai',
        'youbot',
        'diffbot',
        'oai-searchbot',
        'meta-externalagent',
        'timpibot',
        'amazonbot',
        'bytespider',
        'perplexity-user',
        'googlebot',
        'bot',
        'curl',
        'wget',
    ];

    public function isBot(RequestContext $context): bool
    {
        $userAgent = $context->userAgent ?? '';
        $lowerUserAgent = strtolower($userAgent);

        // 1. Known bot list — case-insensitive substring match
        $botUaMatch = false;
        foreach (self::BOT_LIST as $bot) {
            if (str_contains($lowerUserAgent, $bot)) {
                $botUaMatch = true;
                break;
            }
        }

        // 2. Headless browser detection
        $hasHeadlessKeyword = str_contains($lowerUserAgent, 'headless')
            || str_contains($lowerUserAgent, 'puppeteer');
        $headlessIndicators = $hasHeadlessKeyword || $context->secChUa === null;

        $isBrowserMissingSecChUa = ! $hasHeadlessKeyword && $context->secChUa === null;

        // 3. Missing standard headers — many bots omit these
        $missingHeaders = ($context->accept === null || $context->accept === '')
            || ($context->acceptLanguage === null || $context->acceptLanguage === '');

        // Safari/Mozilla special case: likely a real browser missing sec-ch-ua
        if (str_contains($lowerUserAgent, 'safari') || str_contains($lowerUserAgent, 'mozilla')) {
            if ($headlessIndicators && $isBrowserMissingSecChUa) {
                return false;
            }
        }

        return $botUaMatch || $headlessIndicators || $missingHeaders;
    }
}
