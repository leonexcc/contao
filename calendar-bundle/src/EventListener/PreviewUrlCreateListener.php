<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CalendarBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Event\PreviewUrlCreateEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
class PreviewUrlCreateListener
{
    private RequestStack $requestStack;
    private ContaoFramework $framework;

    public function __construct(RequestStack $requestStack, ContaoFramework $framework)
    {
        $this->requestStack = $requestStack;
        $this->framework = $framework;
    }

    /**
     * Adds the calendar ID to the front end preview URL.
     */
    public function __invoke(PreviewUrlCreateEvent $event): void
    {
        if (!$this->framework->isInitialized() || 'calendar' !== $event->getKey()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        // Return on the calendar list page
        if ('tl_calendar_events' === $request->query->get('table') && !$request->query->has('act')) {
            return;
        }

        if ((!$id = $this->getId($event, $request)) || (!$eventModel = $this->getEventModel($id))) {
            return;
        }

        $event->setQuery('calendar='.$eventModel->id);
    }

    /**
     * @return int|string
     */
    private function getId(PreviewUrlCreateEvent $event, Request $request)
    {
        // Overwrite the ID if the event settings are edited
        if ('tl_calendar_events' === $request->query->get('table') && 'edit' === $request->query->get('act')) {
            return $request->query->get('id');
        }

        return $event->getId();
    }

    /**
     * @param int|string $id
     */
    private function getEventModel($id): ?CalendarEventsModel
    {
        return $this->framework->getAdapter(CalendarEventsModel::class)->findByPk($id);
    }
}
