<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\EventListener;

use Contao\CoreBundle\Crawl\Escargot\Factory;
use Contao\CoreBundle\Search\Document;
use Contao\CoreBundle\Search\Indexer\IndexerException;
use Contao\CoreBundle\Search\Indexer\IndexerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * @internal
 */
class SearchIndexListener
{
    public const FEATURE_INDEX = 0b01;
    public const FEATURE_DELETE = 0b10;

    private IndexerInterface $indexer;
    private string $fragmentPath;
    private int $enabledFeatures;

    public function __construct(IndexerInterface $indexer, string $fragmentPath = '_fragment', int $enabledFeatures = self::FEATURE_INDEX | self::FEATURE_DELETE)
    {
        $this->indexer = $indexer;
        $this->fragmentPath = $fragmentPath;
        $this->enabledFeatures = $enabledFeatures;
    }

    /**
     * Checks if the request can be indexed and forwards it accordingly.
     */
    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        // Only handle GET requests (see #1194)
        if (!$request->isMethod(Request::METHOD_GET)) {
            return;
        }

        // Do not index if called by crawler
        if (Factory::USER_AGENT === $request->headers->get('User-Agent')) {
            return;
        }

        // Do not handle fragments
        if (preg_match('~(?:^|/)'.preg_quote($this->fragmentPath, '~').'/~', $request->getPathInfo())) {
            return;
        }

        $response = $event->getResponse();

        // Do not index if the X-Robots-Tag header contains "noindex"
        if (false !== strpos($response->headers->get('X-Robots-Tag', ''), 'noindex')) {
            return;
        }

        $document = Document::createFromRequestResponse($request, $response);

        try {
            $robots = $document->getContentCrawler()->filterXPath('//head/meta[@name="robots"]')->first()->attr('content');

            // Do not index if the meta robots tag contains "noindex"
            if (false !== strpos($robots, 'noindex')) {
                return;
            }
        } catch (\Exception $e) {
            // No meta robots tag found
        }

        $lds = $document->extractJsonLdScripts();

        // If there are no json ld scripts at all, this should not be handled by our indexer
        if (0 === \count($lds)) {
            return;
        }

        try {
            $success = $event->getResponse()->isSuccessful();

            if ($success && $this->enabledFeatures & self::FEATURE_INDEX) {
                $this->indexer->index($document);
            }

            if (!$success && $this->enabledFeatures & self::FEATURE_DELETE) {
                $this->indexer->delete($document);
            }
        } catch (IndexerException $e) {
            // ignore
        }
    }
}
