<?php
namespace Niisan\Laravel\GoogleCalendar;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Google\Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Google_Service_Calendar_FreeBusyRequest;
use Google_Service_Calendar_FreeBusyRequestItem;
use Google_Service_Oauth2;
use Google_Service_Oauth2_Userinfo;
use Illuminate\Support\Facades\Date;
use Niisan\Laravel\GoogleCalendar\Models\Token;
use RuntimeException;

class OauthCalendarService
{

    /** Client $client google api client */
    private Client $client;
    private Google_Service_Oauth2 $oauth_service;
    private Google_Service_Calendar $calendar_service;

    /** array $config */
    private $config;

    /** string $access_token */
    private $access_token;

    /** string $refresh_token */
    private $refresh_token;

    protected $functionsWhenTokenRefreshed = [];

    public function __construct(
        Client $client,
        Google_Service_Oauth2 $oauth_service,
        Google_Service_Calendar $calendar_service
    ) {
        $this->client = $client;
        $this->oauth_service = $oauth_service;
        $this->calendar_service = $calendar_service;
    }

    /**
     * Get Oauth authorize url.
     *
     * @param string $redirect
     * @param bool   $forece_approval_prompt true: user must authorize prompt
     * @return string
     */
    public function getAuthUri(string $redirect = null, bool $forece_approval_prompt = true): string
    {
        $redirect = $redirect ?? config('google-calendar.redirect');
        $this->client->setRedirectUri($redirect);
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt(($forece_approval_prompt) ? 'force': 'auto');
        foreach (config('google-calendar.scopes') as $scope) {
            $this->client->addScope($scope);
        }
        return $this->client->createAuthUrl();
    }

    /**
     * fetch token by code.
     *
     * @param string $code
     * @return Token
     */
    public function getTokenByCode(string $code): Token
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);
        $this->checkTokenError($token);
        return new Token($token);
    }

    /**
     * get user info
     * require scope: email, profile, openId
     *
     * @return Google_Service_Oauth2_Userinfo
     */
    public function getUserInfo($user): Google_Service_Oauth2_Userinfo
    {
        $this->setAccessToken($user);
        return $this->oauth_service->userinfo->get();
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * get event list
     *
     * @param mixed $user
     *
     * @return Google_Service_Calendar_Event[]
     */
    public function getEventList($user, $config): array
    {
        $this->setAccessToken($user);
        $option = $this->setEventSearchConfig($config);
        $events = $this->calendar_service->events->listEvents('primary', $option);
        $ret = [];

        while (true) {
            foreach ($events->getItems() as $event) {
                $ret[] = $event;
            }
            $pageToken = $events->getNextPageToken();
            if ($pageToken) {
                $option['pageToken'] = $pageToken;
                $events = $this->calendar_service->events->listEvents('primary', $option);
            } else {
                break;
            }
        }
        return $ret;
    }

    /**
     * create event
     *
     * @param $user
     * @param array $data
     * @return Google_Service_Calendar_Event
     */
    public function createEvent($user, array $data, $config = []): Google_Service_Calendar_Event
    {
        $event = new Google_Service_Calendar_Event([
            'summary' => $data['summary'],
            'description' => $data['description'] ?? null,
            'start' => [
                'dateTime' => Carbon::parse($data['start'])->format(DATE_RFC3339)
            ],
            'end' => [
                'dateTime' => Carbon::parse($data['end'])->format(DATE_RFC3339)
            ],
            'conferenceData' => $data['conferenceData'] ?? [],
        ]);
        $this->setAccessToken($user);
        $new_event = $this->calendar_service->events->insert('primary', $event, $config);
        return $new_event;
    }

    /**
     * get event.
     *
     * @param [type] $user
     * @param string $event_id
     * @return Google_Service_Calendar_Event
     */
    public function getEvent($user, string $event_id): Google_Service_Calendar_Event
    {
        $this->setAccessToken($user);
        return $this->calendar_service->events->get('primary', $event_id);
    }

    /**
     * update event.
     * allowed params are summary, description, start, and end.
     * start and end params must be date format string.
     *
     * @param [type] $user
     * @param string $event_id
     * @param array $data
     * @return Google_Service_Calendar_Event
     */
    public function updateEvent($user, string $event_id, array $data): Google_Service_Calendar_Event
    {
        $event = $this->getEvent($user, $event_id);

        if (isset($data['summary'])) {
            $event->setSummary($data['summary']);
        }

        if (isset($data['description'])) {
            $event->setDescription($data['description']);
        }

        if (isset($data['start'])) {
            $event->setStart($this->createDateObject($data['start']));
        }

        if (isset($data['end'])) {
            $event->setEnd($this->createDateObject($data['end']));
        }

        if (isset($data['status'])) {
            $event->setStatus($data['status']);
        }

        $updated_event = $this->calendar_service->events->update('primary', $event_id, $event);
        return $updated_event;
    }

    /**
     * delete event
     *
     * @param $user
     * @param string $event_id
     * @return mixed
     */
    public function deleteEvent($user, $event_id)
    {
        $this->setAccessToken($user);
        return $this->calendar_service->events->delete('primary', $event_id);
    }

    /**
     * get holiday list.
     *
     * @param [type] $user
     * @param [type] $date
     * @param integer $span
     * @return void
     */
    public function getHolidays($user, $date = null, $span = 365)
    {
        $this->setAccessToken($user);
        $start = ($date) ? CarbonImmutable::parse($date): CarbonImmutable::now()->firstOfYear();
        $end = $start->addDays($span);
        $events = $this->calendar_service->events->listEvents(config('google-calendar.holiday_id'), [
            'timeMax' => $end->format(DATE_RFC3339),
            'timeMin' => $start->format(DATE_RFC3339)
        ]);

        $ret = [];
        foreach ($events->getItems() as $event) {
            $ret[$event->start->date] = $event->getSummary();
        }

        return $ret;
    }

    /**
     * get User's free busy info.
     *
     * @param $user
     * @param array $config
     * @return array
     */
    public function getFreeBusy($user, $config): array
    {
        $this->setAccessToken($user);
        $items = $config['items'] ?? ['primary'];
        $req = new Google_Service_Calendar_FreeBusyRequest();
        $req->setTimeMin(Carbon::parse($config['timeMin'])->format(DATE_RFC3339));
        $req->setTimeMax(Carbon::parse($config['timeMax'])->format(DATE_RFC3339));
        $req->setTimeZone($config['timezone'] ?? config('app.timezone', null), null);
        $reqItems = [];
        foreach ($items as $item) {
            $reqItem = new Google_Service_Calendar_FreeBusyRequestItem();
            $reqItem->setId($item);
            $reqItems[] = $reqItem;
        }
        $req->setItems($reqItems);
        $res = $this->calendar_service->freebusy->query($req);
        $ret = [];
        foreach ($res->calendars as $calendar) {
            foreach ($calendar['busy'] as $busy) {
                $ret[] = ['start' => $busy->start, 'end' => $busy->end];
            }
        }
        
        return $ret;
    }

    /**
     * set access token.
     *
     * @param $user
     *
     * @return void
     */
    private function setAccessToken($user):void
    {
        $this->checkUser($user);
        $this->client->setAccessToken($user->access_token);
        if (Carbon::now()->timestamp >= $user->expires - 30) {
            $token = $this->client->fetchAccessTokenWithRefreshToken($user->refresh_token);
            $token['expires'] = Carbon::now()->timestamp + $token['expires_in'];
            if (config('google-calendar.events.token_refreshed')) {
                config('google-calendar.events.token_refreshed')::dispatch($user, $token);
            }
        }
    }

    /**
     * check valid user
     *
     * @param [type] $user
     * @return void
     */
    private function checkUser($user): void
    {
        foreach (['access_token', 'refresh_token', 'expires'] as $field) {
            if ($user->$field === null) {
                throw new \Exception('$user must have a field: ' . $field);
            }
        }
    }

    /**
     * set event search config
     *
     * @param array $config
     * @return array
     */
    private function setEventSearchConfig(array $config): array
    {
        $ret = [
            'orderBy' => $config['orderBy'] ?? null,
            'q' => $config['search'] ?? null,
            'timeMax' => (isset($config['timeMax'])) ? Carbon::parse($config['timeMax'])->format(DATE_RFC3339): null,
            'timeMin' => (isset($config['timeMin'])) ? Carbon::parse($config['timeMin'])->format(DATE_RFC3339): null,
            'timeZone' => $config['timeZone'] ?? null,
            'updatedMin' => (isset($config['updatedMin'])) ? Carbon::parse($config['updatedMin'])->format(DATE_RFC3339): null,
            'maxResults' => $config['maxResults'] ?? null,
            'singleEvents' => $config['singleEvents'] ?? true,
        ];

        foreach (array_keys($ret) as $key) {
            if ($ret[$key] === null) {
                unset($ret[$key]);
            }
        }

        return $ret;
    }

    private function checkTokenError(array $response)
    {
        if (isset($response['error'])) {
            $err = 'Return Error Message: ';
            foreach ($response as $key => $val) {
                $err .= "$key: $val, ";
            }
            throw new RuntimeException($err);
        }
    }

    /**
     * create datetime object for google calendar service.
     *
     * @param string $date
     * @return Google_Service_Calendar_EventDateTime
     */
    private function createDateObject(string $date): Google_Service_Calendar_EventDateTime
    {
        $obj = new Google_Service_Calendar_EventDateTime;
        $obj->setDateTime(Carbon::parse($date)->format(DATE_RFC3339));
        return $obj;
    }
}
