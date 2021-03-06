<?php

/*
 * @copyright   2017 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Executioner;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\DecisionAccessor;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\Executioner\Event\DecisionExecutioner as Executioner;
use Mautic\CampaignBundle\Executioner\Exception\CampaignNotExecutableException;
use Mautic\CampaignBundle\Executioner\Exception\DecisionNotApplicableException;
use Mautic\CampaignBundle\Executioner\Result\Responses;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Psr\Log\LoggerInterface;

class RealTimeExecutioner
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LeadModel
     */
    private $leadModel;

    /**
     * @var Lead
     */
    private $contact;

    /**
     * @var array
     */
    private $events;

    /**
     * @var EventRepository
     */
    private $eventRepository;

    /**
     * @var EventExecutioner
     */
    private $executioner;

    /**
     * @var Executioner
     */
    private $decisionExecutioner;

    /**
     * @var EventCollector
     */
    private $collector;

    /**
     * @var EventScheduler
     */
    private $scheduler;

    /**
     * @var ContactTracker
     */
    private $contactTracker;

    /**
     * @var Responses
     */
    private $responses;

    /**
     * RealTimeExecutioner constructor.
     *
     * @param LoggerInterface  $logger
     * @param LeadModel        $leadModel
     * @param EventRepository  $eventRepository
     * @param EventExecutioner $executioner
     * @param Executioner      $decisionExecutioner
     * @param EventCollector   $collector
     * @param EventScheduler   $scheduler
     * @param ContactTracker   $contactTracker
     */
    public function __construct(
        LoggerInterface $logger,
        LeadModel $leadModel,
        EventRepository $eventRepository,
        EventExecutioner $executioner,
        Executioner $decisionExecutioner,
        EventCollector $collector,
        EventScheduler $scheduler,
        ContactTracker $contactTracker
    ) {
        $this->logger              = $logger;
        $this->leadModel           = $leadModel;
        $this->eventRepository     = $eventRepository;
        $this->executioner         = $executioner;
        $this->decisionExecutioner = $decisionExecutioner;
        $this->collector           = $collector;
        $this->scheduler           = $scheduler;
        $this->contactTracker      = $contactTracker;
    }

    /**
     * @param string      $type
     * @param mixed       $passthrough
     * @param string|null $channel
     * @param int|null    $channelId
     *
     * @return Responses
     *
     * @throws Dispatcher\Exception\LogNotProcessedException
     * @throws Dispatcher\Exception\LogPassedAndFailedException
     * @throws Exception\CannotProcessEventException
     * @throws Scheduler\Exception\NotSchedulableException
     */
    public function execute($type, $passthrough = null, $channel = null, $channelId = null)
    {
        $this->responses = new Responses();
        $now             = new \DateTime();

        $this->logger->debug('CAMPAIGN: Campaign triggered for event type '.$type.'('.$channel.' / '.$channelId.')');

        // Kept for BC support although not sure we need this
        defined('MAUTIC_CAMPAIGN_NOT_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_NOT_SYSTEM_TRIGGERED', 1);

        try {
            $this->fetchCurrentContact();
        } catch (CampaignNotExecutableException $exception) {
            $this->logger->debug('CAMPAIGN: '.$exception->getMessage());

            return $this->responses;
        }

        try {
            $this->fetchCampaignData($type);
        } catch (CampaignNotExecutableException $exception) {
            $this->logger->debug('CAMPAIGN: '.$exception->getMessage());

            return $this->responses;
        }

        /** @var Event $event */
        foreach ($this->events as $event) {
            try {
                $this->evaluateDecisionForContact($event, $passthrough, $channel, $channelId);
            } catch (DecisionNotApplicableException $exception) {
                $this->logger->debug('CAMPAIGN: Event ID '.$event->getId().' is not applicable ('.$exception->getMessage().')');

                continue;
            }

            $children = $event->getPositiveChildren();
            if (!$children->count()) {
                $this->logger->debug('CAMPAIGN: Event ID '.$event->getId().' has no positive children');

                continue;
            }

            $this->executeAssociatedEvents($children, $now);
        }

        // Save any changes to the contact done by the listeners
        if ($this->contact->getChanges()) {
            $this->leadModel->saveEntity($this->contact, false);
        }

        return $this->responses;
    }

    /**
     * @param ArrayCollection $children
     * @param \DateTime       $now
     *
     * @throws Dispatcher\Exception\LogNotProcessedException
     * @throws Dispatcher\Exception\LogPassedAndFailedException
     * @throws Exception\CannotProcessEventException
     * @throws Scheduler\Exception\NotSchedulableException
     */
    private function executeAssociatedEvents(ArrayCollection $children, \DateTime $now)
    {
        /** @var Event $child */
        foreach ($children as $child) {
            $executionDate = $this->scheduler->getExecutionDateTime($child, $now);
            $this->logger->debug(
                'CAMPAIGN: Event ID# '.$child->getId().
                ' to be executed on '.$executionDate->format('Y-m-d H:i:s')
            );

            if ($this->scheduler->shouldSchedule($executionDate, $now)) {
                $this->scheduler->scheduleForContact($child, $executionDate, $this->contact);
                continue;
            }

            $this->executioner->executeForContact($child, $this->contact, $this->responses);
        }
    }

    /**
     * @param Event       $event
     * @param mixed       $passthrough
     * @param string|null $channel
     * @param int|null    $channelId
     *
     * @throws DecisionNotApplicableException
     * @throws Exception\CannotProcessEventException
     */
    private function evaluateDecisionForContact(Event $event, $passthrough = null, $channel = null, $channelId = null)
    {
        $this->logger->debug('CAMPAIGN: Executing '.$event->getType().' ID '.$event->getId().' for contact ID '.$this->contact->getId());

        if ($event->getEventType() !== Event::TYPE_DECISION) {
            @trigger_error(
                "{$event->getType()} is not assigned to a decision and no longer supported. ".
                'Check that you are executing RealTimeExecutioner::execute for an event registered as a decision.',
                E_USER_DEPRECATED
            );

            throw new DecisionNotApplicableException("Event {$event->getId()} is not a decision.");
        }

        // If channels do not match up, there's no need to go further
        if ($channel && $event->getChannel() && $channel !== $event->getChannel()) {
            throw new DecisionNotApplicableException("Channels, $channel and {$event->getChannel()}, do not match.");
        }

        if ($channel && $channelId && $event->getChannelId() && $channelId !== $event->getChannelId()) {
            throw new DecisionNotApplicableException("Channel IDs, $channelId and {$event->getChannelId()}, do not match for $channel.");
        }

        /** @var DecisionAccessor $config */
        $config = $this->collector->getEventConfig($event);
        $this->decisionExecutioner->evaluateForContact($config, $event, $this->contact, $passthrough, $channel, $channelId);
    }

    /**
     * @throws CampaignNotExecutableException
     */
    private function fetchCurrentContact()
    {
        $this->contact = $this->contactTracker->getContact();
        if (!$this->contact instanceof Lead || !$this->contact->getId()) {
            throw new CampaignNotExecutableException('Unidentifiable contact');
        }

        $this->logger->debug('CAMPAIGN: Current contact ID# '.$this->contact->getId());
    }

    /**
     * @param $type
     *
     * @throws CampaignNotExecutableException
     */
    private function fetchCampaignData($type)
    {
        if (!$this->events = $this->eventRepository->getContactPendingEvents($this->contact->getId(), $type)) {
            throw new CampaignNotExecutableException('Contact does not have any applicable '.$type.' associations.');
        }

        $this->logger->debug('CAMPAIGN: Found '.count($this->events).' events to analyize for contact ID '.$this->contact->getId());
    }
}
