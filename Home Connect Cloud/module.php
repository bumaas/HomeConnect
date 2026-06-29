<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/WebOAuthModule.php';
include_once __DIR__ . '/api-test.php';

class HomeConnectCloud extends WebOAuthModule
{
    use TestAPI;
    // Simulation
    // const HOME_CONNECT_BASE = 'https://simulator.home-connect.com/api/';
    // private $oauthIdentifer = 'home_connect_dev';

    //Real
    public const HOME_CONNECT_BASE = 'https://api.home-connect.com/api/';
    private $oauthIdentifer = 'home_connect';

    private $oauthServer = 'oauth.ipmagic.de';
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, $this->oauthIdentifer);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeInteger('RetryCounter', 0);

        $this->RegisterAttributeString('Token', '');

        $this->RegisterAttributeString('RateError', '');
        $this->RegisterAttributeInteger('RateLimitUntil', 0);

        $this->RequireParent('{2FADB4B7-FDAB-3C64-3E2C-068A4809849A}');

        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if (IPS_InstanceExists($parent)) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);

        $this->RegisterPropertyString('Language', 'de-DE');

        // A Keep-Alive is sent every 55 seconds. Fail the connection if we miss one
        $this->RegisterTimer('KeepAliveCheck', 60000, 'HC_CheckServerEvents($_IPS[\'TARGET\']);');

        $this->RegisterTimer('Reconnect', 0, 'HC_RegisterServerEvents($_IPS[\'TARGET\']);');

        $this->RegisterTimer('RateLimit', 0, 'HC_ResetRateLimit($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
    }

    /**
     * This function will be called by the register button on the property page!
     */
    public function Register()
    {

        //Return everything which will open the browser
        return 'https://' . $this->oauthServer . '/authorize/' . $this->oauthIdentifer . '?username=' . urlencode(IPS_GetLicensee());
    }

    public function ForwardData($Data)
    {
        $data = json_decode($Data, true);
        $this->SendDebug('Forward', $Data, 0);
        if ($this->isRateLimitActive()) {
            $error = [
                'error' => [
                    'key'         => '429',
                    'description' => $this->ReadAttributeString('RateError') ?: $this->Translate('Home Connect requests are currently rate limited.')
                ]
            ];
            $this->SendDebug('ForwardRateLimit', json_encode($error), 0);
            return json_encode($error);
        }
        try {
            if (isset($data['Payload'])) {
                $this->SendDebug('Payload', $data['Payload'], 0);
                if ($data['Payload'] == 'DELETE') {
                    return $this->deleteRequest($data['Endpoint']);
                }
                return $this->putRequest($data['Endpoint'], $data['Payload']);
            }

            return $this->getRequest($data['Endpoint']);
        } catch (RuntimeException $e) {
            $error = $this->DecodeModuleError($e);
            $this->SendDebug('ForwardError', json_encode($error), 0);
            return json_encode(['error' => $error['error']]);
        }
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug('Receive', $JSONString, 0);

        // A 429 can also arrive through the event stream itself (the /events
        // request is rejected). Feed it into the same rate-limit machinery so the
        // reconnect logic backs off instead of hammering /events every 60s.
        if ($this->applyRateLimitFromStream($JSONString)) {
            return;
        }

        $data = json_decode($JSONString, true);
        switch ($data['Event'] ?? '') {
            case 'KEEP-ALIVE': {
                $this->SendDebug('KeepAlive', 'OK', 0);
                $this->SetBuffer('KeepAlive', time());
                $this->resetRetries();
            }
        }
        $data['DataID'] = '{173D59E5-F949-1C1B-9B34-671217C07B0E}';
        $this->SendDataToChildren(json_encode($data));
    }

    public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($SenderID == $parentID) {
            switch ($MessageID) {
                //A failing requests triggers a status change
                case IM_CHANGESTATUS:
                    // Update SSE if it is faulty gradually increase the reconnect interval
                    if ($Data[0] >= IS_EBASE) {
                        $retries = $this->ReadAttributeInteger('RetryCounter');
                        $retries++;
                        $this->WriteAttributeInteger('RetryCounter', $retries);
                        $retryTime = pow($retries, 2);
                        $this->SetTimerInterval('Reconnect', ($retryTime > 3600 /*1h*/ ? 3600 : $retryTime) * 1000);
                    }
                    break;
            }
        }
        if ($SenderID == $this->InstanceID) {
            switch ($MessageID) {
                case FM_CONNECT:
                    $this->RegisterMessage($Data[0], IM_CHANGESTATUS);
                    $this->ForceRegisterServerEvents();
                    break;
            }
        }
    }

    public function ForceRegisterServerEvents()
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if (IPS_InstanceExists($parent)) {
            IPS_SetProperty($parent, 'Active', true);
            IPS_ApplyChanges($parent);
            $this->RegisterServerEvents();
        }
    }

    public function RegisterServerEvents()
    {
        // Do not (re)connect the event stream while we are rate limited - every
        // reconnect hits /events and counts against the daily quota. Defer the
        // reconnect to the moment the limit expires instead.
        if ($this->isRateLimitActive()) {
            $remaining = $this->ReadAttributeInteger('RateLimitUntil') - time();
            $this->SendDebug('RegisterServerEvents', sprintf('Rate limit active, defer reconnect by %ds', $remaining), 0);
            $this->SetTimerInterval('Reconnect', max(1, $remaining) * 1000);
            return;
        }
        try {
            $url = self::HOME_CONNECT_BASE . 'homeappliances/events';
            $this->SendDebug('url', $url, 0);
            $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if (!IPS_GetProperty($parent, 'Active')) {
                echo $this->Translate('IO instance is not active');
                return;
            }
            IPS_SetProperty($parent, 'URL', $url);
            IPS_SetProperty($parent, 'Headers', json_encode([['Name' => 'Authorization', 'Value' => 'Bearer ' . $this->FetchAccessToken()]]));
            IPS_ApplyChanges($parent);

            // Mark connection as good for the moment
            $this->SetBuffer('KeepAlive', time());

            $this->SetTimerInterval('Reconnect', 0);
        } catch (RuntimeException $e) {
            $error = $this->DecodeModuleError($e);
            $this->SendDebug('RegisterServerEventsError', json_encode($error), 0);
            echo $error['error']['description'];
        }
    }

    public function CheckServerEvents()
    {
        // While rate limited the missing keep-alive is expected - do not reconnect.
        if ($this->isRateLimitActive()) {
            return;
        }
        if ($this->HasActiveParent()) {
            if (time() - intval($this->GetBuffer('KeepAlive')) > 60 /* Seconds */) {
                $this->SendDebug('KeepAlive', 'Failed. Reregistering...', 0);
                $this->RegisterServerEvents();
            }
        }
    }

    public function GetConfigurationForParent()
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $url = IPS_GetProperty($parent, 'URL');
        $header = IPS_GetProperty($parent, 'Headers');
        return json_encode([
            'URL'     => $url ? $url : '',
            'Headers' => $header ? $header : []
        ]);
    }

    public function ResetRateLimit()
    {
        $this->WriteAttributeString('RateError', '');
        $this->WriteAttributeInteger('RateLimitUntil', 0);
        $this->updateRateLimitNotice();
        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('RateLimit', 0);

        // Limit is over - resume the event stream exactly once.
        $this->SetTimerInterval('Reconnect', 0);
        $this->RegisterServerEvents();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $rateError = $this->ReadAttributeString('RateError');
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') !== 'RateLimitNotice') {
                continue;
            }
            $element['caption'] = $rateError;
            $element['visible'] = $rateError !== '';
            break;
        }

        return json_encode($form);
    }

    /**
     * This function will be called by the OAuth control. Visibility should be protected!
     */
    protected function ProcessOAuthData()
    {

        //Lets assume requests via GET are for code exchange. This might not fit your needs!
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            if (!isset($_GET['code'])) {
                die('Authorization Code expected');
            }

            // When the page gets reloaded we might use the code twice which results in our token being revoked by the server.
            $lastCode = $this->GetBuffer('LastCode');
            if ($lastCode == $_GET['code']) {
                return;
            } else {
                $this->SetBuffer('LastCode', $_GET['code']);
            }

            $token = $this->FetchRefreshToken($_GET['code']);

            $this->SendDebug('ProcessOAuthData', "OK! Let's save the Refresh Token permanently", 0);

            $this->WriteAttributeString('Token', $token);
            $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($token, 0, 16) . '...');

            $this->ForceRegisterServerEvents();

        } else {

            //Just print raw post data!
            echo file_get_contents('php://input');
        }
    }

    /**
     * Detects a 429 rate-limit response carried by the SSE stream and activates
     * the rate limit. Envelope-agnostic: matches on the raw payload because the
     * IO wraps the body in different ways. Returns true if a 429 was handled.
     */
    private function applyRateLimitFromStream(string $JSONString): bool
    {
        if (!preg_match('/"key"\s*:\s*"429"/', $JSONString)) {
            return false;
        }

        $retryAfter = preg_match('/remaining period of (\d+) seconds/', $JSONString, $m) ? (int) $m[1] : 60;

        $type = null;
        if (strpos($JSONString, 'in 1 day') !== false) {
            $type = 'day';
        } elseif (strpos($JSONString, 'in 1 minute') !== false) {
            $type = 'minute';
        }

        $this->SendDebug('ReceiveRateLimit', sprintf('429 from event stream, blocking for %ds', $retryAfter), 0);
        $this->applyRateLimit($retryAfter, $type);
        return true;
    }

    private function resetRetries()
    {
        $this->SetTimerInterval('Reconnect', 0);
        $this->WriteAttributeInteger('RetryCounter', 0);
    }

    private function FetchRefreshToken($code)
    {
        $this->SendDebug('FetchRefreshToken', 'Use Authentication Code to get our precious Refresh Token!', 0);

        if (trim((string) $code) == '') {
            $this->ThrowModuleError(
                'Client.Error.AuthenticationRequired',
                $this->Translate('Home Connect registration is incomplete. Please reconnect "Home Connect Cloud" using "Register".'),
                'OAuth authorization failed: Authorization Code missing'
            );
        }

        //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
        $data = $this->RequestOAuthToken(
            ['code' => $code],
            'authorization'
        );

        //Save temporary access token
        $this->FetchAccessToken($data->access_token, time() + $data->expires_in);

        //Return RefreshToken
        return $data->refresh_token;
    }

    private function FetchAccessToken($Token = '', $Expires = 0)
    {

        //Exchange our Refresh Token for a temporary Access Token
        if ($Token == '' && $Expires == 0) {

            //Check if we already have a valid Token in cache
            $data = $this->GetBuffer('AccessToken');
            if ($data != '') {
                $data = json_decode($data);
                if (time() < $data->Expires) {
                    $this->SendDebug('FetchAccessToken', 'OK! Access Token is valid until ' . date('d.m.y H:i:s', $data->Expires), 0);
                    return $data->Token;
                }
            }

            $this->SendDebug('FetchAccessToken', 'Use Refresh Token to get new Access Token!', 0);

            $refreshToken = trim($this->ReadAttributeString('Token'));
            if ($refreshToken == '') {
                $this->ThrowModuleError(
                    'Client.Error.AuthenticationRequired',
                    $this->Translate('Home Connect login is missing. Please connect "Home Connect Cloud" using "Register" and then register the server events again.'),
                    'OAuth token refresh failed: Refresh Token missing'
                );
            }

            //If we slipped here we need to fetch the access token
            $data = $this->RequestOAuthToken(
                ['refresh_token' => $refreshToken],
                'refresh'
            );

            //Update parameters to properly cache it in the next step
            $Token = $data->access_token;
            $Expires = time() + $data->expires_in;

            //Update Refresh Token if we received one! (This is optional)
            if (isset($data->refresh_token)) {
                $this->SendDebug('FetchAccessToken', "NEW! Let's save the updated Refresh Token permanently", 0);

                $this->WriteAttributeString('Token', $data->refresh_token);
                $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($data->refresh_token, 0, 16) . '...');
            }
        }

        $this->SendDebug('FetchAccessToken', 'CACHE! New Access Token is valid until ' . date('d.m.y H:i:s', $Expires), 0);

        //Save current Token
        $this->SetBuffer('AccessToken', json_encode(['Token' => $Token, 'Expires' => $Expires]));

        //Return current Token
        return $Token;
    }

    private function RequestOAuthToken(array $payload, string $requestType)
    {
        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'        => 'POST',
                'content'       => http_build_query($payload),
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);
        $responseHeader = isset($http_response_header) ? $http_response_header : [];
        $responseCode = $this->GetHttpResponseCode($responseHeader);
        $rawResult = is_string($result) ? $result : '';

        $this->SendDebug('OAuth ' . $requestType . ' HTTP', json_encode($responseHeader), 0);
        $this->SendDebug('OAuth ' . $requestType . ' Response', $rawResult, 0);

        $data = json_decode($rawResult);

        if (isset($data->token_type) && $data->token_type == 'Bearer' && isset($data->access_token)) {
            return $data;
        }

        $oauthError = $this->BuildOAuthError($requestType, $responseCode, $data, $rawResult);
        $this->ThrowModuleError($oauthError['key'], $oauthError['description'], $oauthError['debug']);
    }

    private function BuildOAuthError(string $requestType, int $responseCode, $data, string $rawResult): array
    {
        $context = $requestType == 'refresh' ? 'token refresh' : 'authorization';
        $error = is_object($data) && isset($data->error) ? (string) $data->error : '';
        $description = is_object($data) && isset($data->error_description) ? trim((string) $data->error_description) : '';

        if ($error == 'invalid_grant') {
            $reason = $description != '' ? $description : 'Refresh Token invalid or expired';
            return [
                'key'         => 'Client.Error.AuthenticationExpired',
                'description' => $this->Translate('Home Connect login expired or was revoked. Please reconnect "Home Connect Cloud" using "Register" and then register the server events again.'),
                'debug'       => 'OAuth ' . $context . ' failed: invalid_grant (' . $reason . ')'
            ];
        }

        if ($responseCode >= 500 || $responseCode == 0) {
            $reason = $description != '' ? $description : ($rawResult != '' ? trim($rawResult) : 'No HTTP response');
            return [
                'key'         => 'Client.Error.AuthenticationServer',
                'description' => $this->Translate('Home Connect login could not be refreshed right now. Please try again later. If the problem persists, reconnect "Home Connect Cloud".'),
                'debug'       => 'OAuth ' . $context . ' failed: server problem (HTTP ' . $responseCode . ', ' . $reason . ')'
            ];
        }

        if ($error != '') {
            $reason = $description != '' ? $description : 'No error description';
            return [
                'key'         => 'Client.Error.Authentication',
                'description' => $this->Translate('Home Connect login failed. Please check "Home Connect Cloud" and reconnect if necessary.'),
                'debug'       => 'OAuth ' . $context . ' failed: ' . $error . ' (' . $reason . ')'
            ];
        }

        if ($responseCode >= 400) {
            $reason = $rawResult != '' ? trim($rawResult) : 'Empty response body';
            return [
                'key'         => 'Client.Error.AuthenticationHttp',
                'description' => $this->Translate('Home Connect login failed. Please reconnect "Home Connect Cloud".'),
                'debug'       => 'OAuth ' . $context . ' failed: HTTP ' . $responseCode . ' (' . $reason . ')'
            ];
        }

        return [
            'key'         => 'Client.Error.AuthenticationUnexpected',
            'description' => $this->Translate('Home Connect login returned an unexpected response. Please reconnect "Home Connect Cloud".'),
            'debug'       => 'OAuth ' . $context . ' failed: unexpected token response'
        ];
    }

    private function GetHttpResponseCode(array $responseHeader): int
    {
        if (count($responseHeader) == 0) {
            return 0;
        }

        $parts = explode(' ', $responseHeader[0]);
        if (!isset($parts[1])) {
            return 0;
        }

        return intval($parts[1]);
    }

    private function ThrowModuleError(string $key, string $description, string $debug): void
    {
        throw new RuntimeException(json_encode([
            'error' => [
                'key'         => $key,
                'description' => $description,
                'debug'       => $debug
            ]
        ]));
    }

    private function DecodeModuleError(RuntimeException $e): array
    {
        $data = json_decode($e->getMessage(), true);
        if (!is_array($data) || !isset($data['error'])) {
            return [
                'error' => [
                    'key'         => 'Client.Error.Module',
                    'description' => $this->Translate('Home Connect request failed.'),
                    'debug'       => $e->getMessage()
                ]
            ];
        }

        return $data;
    }

    private function FetchData($url)
    {
        $opts = [
            'http'=> [
                'method'        => 'POST',
                'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" . 'Content-Type: application/json' . "\r\n",
                'content'       => '{"JSON-KEY":"THIS WILL BE LOOPED BACK AS RESPONSE!"}',
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($opts);

        $result = file_get_contents($url, false, $context);
        if ((strpos($http_response_header[0], '200') === false)) {
            echo $http_response_header[0] . PHP_EOL . $result;
            return false;
        }

        return $result;
    }

    private function isRateLimitActive(): bool
    {
        return $this->ReadAttributeInteger('RateLimitUntil') > time();
    }

    private function updateRateLimitNotice(): void
    {
        $rateError = $this->ReadAttributeString('RateError');
        $this->UpdateFormField('RateLimitNotice', 'caption', $rateError);
        $this->UpdateFormField('RateLimitNotice', 'visible', $rateError !== '');
    }

    private function parseResponseHeaders(array $responseHeader): array
    {
        $head = [];
        foreach ($responseHeader as $header) {
            $values = explode(':', $header, 2);
            if (isset($values[1])) {
                $head[strtolower(trim($values[0]))] = trim($values[1]);
            }
        }

        return $head;
    }

    private function getRateLimitDelay(array $responseHeader): int
    {
        $head = $this->parseResponseHeaders($responseHeader);

        if (isset($head['retry-after']) && is_numeric($head['retry-after'])) {
            return max(1, (int) $head['retry-after']);
        }

        return 60;
    }

    private function handleHttpErrors($code, $responseHeader)
    {
        switch ($code) {
            //Too Many Requests
            case 429:
                $head = $this->parseResponseHeaders($responseHeader);
                $this->applyRateLimit($this->getRateLimitDelay($responseHeader), $head['rate-limit-type'] ?? null);
                return;

        }
    }

    /**
     * Activates the rate-limit state shared by the REST and the SSE path:
     * remembers the block end, schedules the reset timer and updates the notice.
     */
    private function applyRateLimit(int $retryAfter, ?string $type): void
    {
        $retryAfter = max(1, $retryAfter);
        $nextRun = time() + $retryAfter;
        $this->WriteAttributeInteger('RateLimitUntil', $nextRun);
        $this->SetTimerInterval('RateLimit', $retryAfter * 1000);

        $this->WriteAttributeString(
            'RateError',
            $type !== null ?
            sprintf(
                $this->Translate(
                    'The rate limit of %s was reached. Requests are blocked until %s.'
                ),
                $type == 'day' ?
                $this->Translate('1000 calls in 1 day') : $this->Translate('50 calls in 1 minute'),
                date('d.m.Y H:i:s', $nextRun),
            ) : sprintf($this->Translate('A rate limit was reached. Requests are blocked until %s.'), date('d.m.Y H:i:s', $nextRun))
        );
        $this->updateRateLimitNotice();
        if ($this->HasActiveParent() && $this->GetStatus() != IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    private function getData($endpoint)
    {
        $opts = [
            'http'=> [
                'method'        => 'GET',
                'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" .
                                   'Accept-Language: ' . $this->ReadPropertyString('Language') . "\r\n",
                'ignore_errors' => true //Errors will be handled in code
            ]
        ];
        $context = stream_context_create($opts);

        $result = file_get_contents(self::HOME_CONNECT_BASE . $endpoint, false, $context);
        $code = explode(' ', $http_response_header[0])[1];
        $this->SendDebug('HTTP GET', $endpoint . ' -> ' . $code, 0);
        if ($code != 200) {
            $this->SendDebug('HTTP GET Response', $result, 0);
        }
        if ($code == 200) {
            $this->ResetRateLimit();
        } else {
            $this->handleHttpErrors($code, $http_response_header);
        }
        return $result;
    }

    private function putData($endpoint, $content)
    {
        $opts = [
            'http'=> [
                'method'        => 'PUT',
                'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" .
                                   'Content-Length: ' . strlen($content) . "\r\n" .
                                   'Content-Type: application/vnd.bsh.sdk.v1+json' . "\r\n",
                'Accept-Language: ' . $this->ReadPropertyString('Language') . "\r\n",
                'content'       => $content,
                'ignore_errors' => true //Errors will be handled in code
            ]
        ];
        $context = stream_context_create($opts);

        $result = file_get_contents(self::HOME_CONNECT_BASE . $endpoint, false, $context);

        $code = explode(' ', $http_response_header[0])[1];
        $this->SendDebug('HTTP PUT', $endpoint . ' -> ' . $code, 0);
        if ($code != 200 && $code != 201 && $code != 204) {
            $this->SendDebug('HTTP PUT Response', $result, 0);
        }
        if ($code == 204) {
            $this->ResetRateLimit();
        } else {
            $this->handleHttpErrors($code, $http_response_header);
        }

        if ((strpos($http_response_header[0], '201') === false)) {
            return $result;
        }

        return $result;
    }

    private function deleteData($endpoint)
    {
        $opts = [
            'http'=> [
                'method'        => 'DELETE',
                'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" .
                                   'Accept-Language: ' . $this->ReadPropertyString('Language') . "\r\n",
                'ignore_errors' => true //Errors will be handled in code
            ]
        ];
        $context = stream_context_create($opts);
        $this->SendDebug('Request', print_r($context, true), 0);

        $result = file_get_contents(self::HOME_CONNECT_BASE . $endpoint, false, $context);
        $code = explode(' ', $http_response_header[0])[1];
        $this->SendDebug('HTTP DELETE', $endpoint . ' -> ' . $code, 0);
        if ($code != 200 && $code != 204) {
            $this->SendDebug('HTTP DELETE Response', $result, 0);
        }

        if ($code == 204) {
            $this->ResetRateLimit();
        } else {
            $this->handleHttpErrors($code, $http_response_header);
        }

        return $result;
    }
}
