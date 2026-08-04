<?php

namespace App\Services\Google;

use Google\Client;
use Google\Service\Calendar as GoogleCalendarClient;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventExtendedProperties;

/**
 * Google Integration Foundation, Stage 4A — the real implementation of
 * GoogleApiClientInterface, thinly wrapping the official `google/apiclient`
 * SDK. The only class in this codebase that constructs a `\Google\Client`.
 * Every method does exactly what its interface docblock says and nothing
 * more — no persistence, no business logic, no health/diagnostics
 * decisions (those belong to GoogleOAuthService/GoogleTokenRefreshService/
 * GoogleHealthService, which call this class, never the reverse) —
 * mirrors StripeBillingProvider's identical "thin adapter" discipline.
 */
class GoogleClientAdapter implements GoogleApiClientInterface
{
    private function baseClient(): Client
    {
        $client = new Client();
        $client->setClientId((string) config('google.client_id'));
        $client->setClientSecret((string) config('google.client_secret'));
        $client->setRedirectUri((string) config('google.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    public function buildAuthorizationUrl(string $state, array $scopes): string
    {
        $client = $this->baseClient();
        $client->setScopes($scopes);

        return $client->createAuthUrl(null, ['state' => $state]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $token = $this->baseClient()->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google rejected the authorization: ' . ($token['error_description'] ?? $token['error']));
        }
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Google did not return an access token.');
        }

        return $token;
    }

    public function decodeIdToken(string $idToken): array
    {
        // A small, standard clock-skew allowance — Google's own SDK
        // docblock (Client::__construct()'s $jwt config option) points
        // directly at this static property as the supported way to tolerate
        // ordinary drift between this server's clock and Google's, rather
        // than failing ID token verification outright over a few seconds/
        // minutes of difference. 300s (5 minutes) matches the leeway most
        // OAuth/JWT libraries default to or recommend.
        \Firebase\JWT\JWT::$leeway = 300;

        $claims = $this->baseClient()->verifyIdToken($idToken);

        return is_array($claims) ? $claims : [];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $token = $this->baseClient()->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($token['error'])) {
            throw new \RuntimeException($token['error_description'] ?? $token['error']);
        }
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Google did not return a refreshed access token.');
        }

        return $token;
    }

    public function revokeToken(string $token): void
    {
        $client = $this->baseClient();
        $client->revokeToken($token);
    }

    public function listPrimaryCalendarEvents(string $accessToken, int $maxResults): array
    {
        $client = $this->baseClient();
        $client->setAccessToken(['access_token' => $accessToken]);

        $calendar = new GoogleCalendarClient($client);
        $result = $calendar->events->listEvents('primary', ['maxResults' => $maxResults]);

        return $result->getItems();
    }

    /**
     * Stage 4B.1/4B.2. $eventBody is the trusted, already-shaped array from
     * App\Services\Calendar\ConsultancyAppointmentCalendarEventPayloadFactory
     * — this method performs no content decisions, only maps it onto the
     * SDK's typed Event object and calls insert() with $sendUpdates passed
     * through exactly as given (see GoogleCalendarProvider, which always
     * passes 'none'). When `$eventBody['request_conference']` is true, a
     * Google Meet conference is requested via `conferenceData.createRequest`
     * in THE SAME insert() call — `conferenceDataVersion: 1` is required
     * for Google to honour `conferenceData` in the request body at all
     * (silently ignored otherwise). `requestId` is `$eventBody['correlation_key']`
     * — stable across every retry of this logical operation (see this
     * interface's own docblock on GoogleApiClientInterface for why a fresh
     * ID must never be generated per attempt).
     */
    public function insertPrimaryCalendarEvent(string $accessToken, array $eventBody, string $sendUpdates): array
    {
        $client = $this->baseClient();
        $client->setAccessToken(['access_token' => $accessToken]);
        $calendar = new GoogleCalendarClient($client);

        $event = new GoogleCalendarEvent();
        $event->setSummary($eventBody['summary']);
        $event->setDescription($eventBody['description']);

        $start = new EventDateTime();
        $start->setDateTime($eventBody['start']['date_time']);
        $start->setTimeZone($eventBody['start']['timezone']);
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDateTime($eventBody['end']['date_time']);
        $end->setTimeZone($eventBody['end']['timezone']);
        $event->setEnd($end);

        $event->setAttendees(array_map(function (array $attendee) {
            $eventAttendee = new EventAttendee();
            $eventAttendee->setEmail($attendee['email']);

            return $eventAttendee;
        }, $eventBody['attendees']));

        $extendedProperties = new EventExtendedProperties();
        $extendedProperties->setPrivate(['suresign_correlation_key' => $eventBody['correlation_key']]);
        $event->setExtendedProperties($extendedProperties);

        $optParams = ['sendUpdates' => $sendUpdates];

        if (!empty($eventBody['request_conference'])) {
            $conferenceData = new ConferenceData();
            $createRequest = new CreateConferenceRequest();
            $createRequest->setRequestId($eventBody['correlation_key']);
            $solutionKey = new ConferenceSolutionKey();
            $solutionKey->setType('hangoutsMeet');
            $createRequest->setConferenceSolutionKey($solutionKey);
            $conferenceData->setCreateRequest($createRequest);
            $event->setConferenceData($conferenceData);

            $optParams['conferenceDataVersion'] = 1;
        }

        $result = $calendar->events->insert('primary', $event, $optParams);

        return [
            'id' => $result->getId(),
            'created' => $result->getCreated(),
            'conference' => $this->normalizeConferenceData($result->getConferenceData()),
        ];
    }

    public function listPrimaryCalendarEventsByPrivateProperty(string $accessToken, string $key, string $value): array
    {
        $client = $this->baseClient();
        $client->setAccessToken(['access_token' => $accessToken]);
        $calendar = new GoogleCalendarClient($client);

        $result = $calendar->events->listEvents('primary', [
            'privateExtendedProperty' => "{$key}={$value}",
        ]);

        return array_map(fn ($event) => [
            'id' => $event->getId(),
            'conference' => $this->normalizeConferenceData($event->getConferenceData()),
        ], $result->getItems());
    }

    /**
     * The one place this adapter reads Google's raw `conferenceData`
     * shape — everything past this method is already a plain, normalised
     * array (status/conference_id/conference_type/entry_points), never a
     * raw SDK object. GoogleCalendarProvider does the further step of
     * resolving a customer-safe join URL from `entry_points`.
     *
     * @return array{status: ?string, conference_id: ?string, conference_type: ?string, entry_points: array<int, array{type: ?string, uri: ?string}>}
     */
    private function normalizeConferenceData(?ConferenceData $conferenceData): array
    {
        if (!$conferenceData) {
            return ['status' => null, 'conference_id' => null, 'conference_type' => null, 'entry_points' => []];
        }

        $status = $conferenceData->getCreateRequest()?->getStatus()?->getStatusCode();
        $solution = $conferenceData->getConferenceSolution();

        $entryPoints = array_map(fn ($entryPoint) => [
            'type' => $entryPoint->getEntryPointType(),
            'uri' => $entryPoint->getUri(),
        ], $conferenceData->getEntryPoints() ?? []);

        return [
            'status' => $status,
            'conference_id' => $conferenceData->getConferenceId(),
            'conference_type' => $solution?->getKey()?->getType(),
            'entry_points' => $entryPoints,
        ];
    }
}
