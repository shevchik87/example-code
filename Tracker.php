<?php

namespace AppBundle\Component\Matches\Analytics;

use AppBundle\Component\Core\Cache\SimpleCacheAdapter;
use AppBundle\Component\Matches\Storage\MarkStorage;
use AppBundle\Component\Matches\UserMarkDto;
use AppBundle\Component\Security\Model\User;
use AppBundle\Component\Tracking\Event\MatchesPickupDeletedUser;
use AppBundle\Component\Tracking\Event\MatchesUserNotFound;
use AppBundle\Component\Tracking\Event\PickupMatchesEvent;
use AppBundle\Component\Tracking\Event\PriorityListLogTimeEvent;
use AppBundle\Component\Tracking\Event\UserFraud;
use DDKit\ProductComponents\Analytics\Tracker as TrackerService;
use AppBundle\Component\Tracking\Type\ActivityTracker;
use AppBundle\Component\User\Helper\GeoHelper;
use AppBundle\Component\User\TempData\TempDataManager;

/**
 * Class Tracker
 * @package AppBundle\Component\Matches\Analytics
 */
class Tracker
{
    /**
     * @var ActivityTracker $activityTracker
     */
    protected $activityTracker;

    /**
     * @var BotDetector $botDetector
     */
    protected $botDetector;

    /**
     * @var ClicksCounter $clicksCounter
     */
    protected $clicksCounter;

    /**
     * @var TempDataManager $tempDataStorage
     */
    protected $tempDataStorage;

    /**
     * @var TrackerService $trackerService
     */
    protected $trackerService;

    /**
     * @var GeoHelper
     */
    protected $geoHelper;

    /**
     * Tracker constructor.
     * @param ActivityTracker $activityTracker
     * @param BotDetector $botDetector
     * @param ClicksCounter $clicksCounter
     * @param TempDataManager $tempDataStorage
     * @param TrackerService $trackerService
     * @param GeoHelper $geoHelper
     */
    public function __construct(
        ActivityTracker $activityTracker,
        BotDetector $botDetector,
        ClicksCounter $clicksCounter,
        TempDataManager $tempDataStorage,
        TrackerService $trackerService,
        GeoHelper $geoHelper
    ) {
        $this->activityTracker = $activityTracker;
        $this->botDetector = $botDetector;
        $this->clicksCounter= $clicksCounter;
        $this->trackerService = $trackerService;
        $this->tempDataStorage = $tempDataStorage;
        $this->geoHelper = $geoHelper;
    }

    /**
     * @param UserMarkDto $userMarkDto
     */
    public function trackNewClickAndMark(UserMarkDto $userMarkDto)
    {
        $senderId = $userMarkDto->getSender()->id;
        $this->clicksCounter->incClicksPerDay($senderId);
        if ($fraudType = $this->botDetector->tryDetectClicker($senderId)) {
            $this->trackFraud($senderId, $fraudType);
        }

        //$this->activityTracker->trackMatch($userMarkDto);

        if (!in_array($userMarkDto->getSenderMark(), [MarkStorage::MARK_YES, MarkStorage::MARK_MAYBE])) {
            return;
        }

        if ($fraudType = $this->botDetector->tryDetectWomanClicker360($senderId)) {
            $this->trackFraud($senderId, $fraudType);
        }
    }

    /**
     * @param int $userId
     * @param string $fraudType
     */
    public function trackFraud(int $userId, string $fraudType): void
    {
        $event = (new UserFraud())
            ->setUserId($userId)
            ->setFraudType($fraudType);
        $this->trackerService->push($event);
    }

    /**
     * @param int $userId
     * @param int $foundUserId
     */
    public function pickupUserTracking(int $userId, ?int $foundUserId = null): void
    {
        $key = 'matches_notfound';
        $notFoundCount = $this->tempDataStorage->getValue($userId, $key);
        if ($foundUserId) {
            if ($notFoundCount) {
                $this->tempDataStorage->delete($userId, $key);
            }
            return;
        }

        $this->tempDataStorage->set($userId, $key, ++$notFoundCount, SimpleCacheAdapter::TTL_MONTH);

        $event = (new MatchesUserNotFound())
            ->setUserId($userId)
            ->setIter($notFoundCount);
        $this->trackerService->push($event);
    }

    /**
     * @param int $userId
     * @param User $contact
     * @param int $attempt
     */
    public function pickupDeletedUserTracking(int $userId, User $contact, int $attempt = 0): void
    {
        $event = (new MatchesPickupDeletedUser())
            ->setUserId($userId)
            ->setContactId($contact->id)
            ->setContactOk($contact->ok)
            ->setAttempt($attempt);
        $this->trackerService->push($event);
    }

    /**
     * @param int $senderId
     * @param int $receiverId
     */
    public function trackSendLike(int $senderId, int $receiverId)
    {
        $this->activityTracker->trackSendLike($senderId, $receiverId);
    }

    /**
     * @param UserMarkDto $userMarkDto
     */
    public function trackUserMark(UserMarkDto $userMarkDto)
    {
        $this->activityTracker->trackUserMark($userMarkDto);
    }

    /**
     * @param User $user
     * @param User|null $contact
     * @param $isPickup bool
     */
    public function trackPickupMatchesUser(User $user, ?User $contact, $isPickup = true)
    {
        $event = new PickupMatchesEvent();
        $userCity = $this->geoHelper->getCityById($user->city_id);
        $event
            ->setUserId($user->id)
            ->setGender($user->gender)
            ->setCityId($user->city_id)
            ->setRegionId($userCity->getSubdivision()->getId())
            ->setCountryId($userCity->getCountry()->getId())
            ->setUserRating($user->getRatingElo())
            ->setIsPickup((int) $isPickup);
        if (!empty($contact)) {
            $contactCity = $this->geoHelper->getCityById($contact->city_id);
            $event
                ->setContactId($contact->id)
                ->setContactCountryId($contactCity->getCountry()->getId())
                ->setContactCtyId($contact->city_id)
                ->setContactGender($contact->gender)
                ->setContactRating($contact->getRatingElo())
                ->setContactRegionId($contactCity->getSubdivision() ? $contactCity->getSubdivision()->getId() : 0);
        }
        $this->trackerService->push($event);
    }

    /**
     * @param int $userId
     * @param int $countList
     * @param int $executeTime
     */
    public function trackPriorityExecuteTime(int $userId, int $countList, int $executeTime)
    {
        $event = new PriorityListLogTimeEvent();
        $event
            ->setUserId($userId)
            ->setCountList($countList)
            ->setTimeExecute($executeTime);
        $this->trackerService->push($event);
    }
}
