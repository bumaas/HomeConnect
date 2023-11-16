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

        $this->RequireParent('{2FADB4B7-FDAB-3C64-3E2C-068A4809849A}');

        $this->RegisterMessage(IPS_GetInstance($this->InstanceID)['ConnectionID'], IM_CHANGESTATUS);

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
        if (isset($data['Payload'])) {
            $this->SendDebug('Payload', $data['Payload'], 0);
            if ($data['Payload'] == 'DELETE') {
                return $this->deleteRequest($data['Endpoint']);
            }
            return $this->putRequest($data['Endpoint'], $data['Payload']);
        }
        return $this->getRequest($data['Endpoint']);
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug('Receive', $JSONString, 0);
        $data = json_decode($JSONString, true);
        switch ($data['Event']) {
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
    }

    public function RegisterServerEvents()
    {
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
    }

    public function CheckServerEvents()
    {
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
        if ($this->GetStatus() != IS_ACTIVE) {
            $this->WriteAttributeString('RateError', '');
        }
        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('RateLimit', 0);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['status'][] = [
            'code'    => IS_EBASE,
            'icon'    => 'error',
            'caption' => $this->ReadAttributeString('RateError'),
        ];

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

            $token = $this->FetchRefreshToken($_GET['code']);

            $this->SendDebug('ProcessOAuthData', "OK! Let's save the Refresh Token permanently", 0);

            $this->WriteAttributeString('Token', $token);
            $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($token, 0, 16) . '...');
        } else {

            //Just print raw post data!
            echo file_get_contents('php://input');
        }
    }

    private function resetRetries()
    {
        $this->SetTimerInterval('Reconnect', 0);
        $this->WriteAttributeInteger('RetryCounter', 0);
    }

    private function FetchRefreshToken($code)
    {
        $this->SendDebug('FetchRefreshToken', 'Use Authentication Code to get our precious Refresh Token!', 0);

        //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'        => 'POST',
                'content'       => http_build_query(['code' => $code]),
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);

        $data = json_decode($result);

        if (!isset($data->token_type) || $data->token_type != 'Bearer') {
            die('Bearer Token expected');
        }

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

            //If we slipped here we need to fetch the access token
            $options = [
                'http' => [
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method'        => 'POST',
                    'content'       => http_build_query(['refresh_token' => $this->ReadAttributeString('Token')]),
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($options);
            $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);

            $data = json_decode($result);

            if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                die('Bearer Token expected');
            }

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

    private function getTimer($name)
    {
        foreach (IPS_GetTimerList() as $timerID) {
            $timer = IPS_GetTimer($timerID);
            if (($timer['InstanceID'] == $this->InstanceID) && ($timer['Name'] == $name)) {
                return $timer;
                break;
            }
        }
        return false;
    }

    private function handleHttpErrors($code, $responseHeader)
    {
        switch ($code) {
            //Too Many Requests
            case 429:
                $head = [];
                foreach ($responseHeader as $header) {
                    $values = explode(':', $header, 2);
                    if (isset($values[1])) {
                        $head[trim($values[0])] = trim($values[1]);
                    }
                }
                $this->SetTimerInterval('RateLimit', $head['Retry-After'] * 1000);
                $timer = $this->getTimer('RateLimit');
                //Fallback to current time
                $nextRun = $timer === false ? time() : $timer['NextRun'];

                $this->WriteAttributeString(
                    'RateError',
                    isset($head['Rate-Limit-Type']) ?
                    sprintf(
                        $this->Translate(
                            'The rate limit of %s was reached. Requests are blocked until %s.'
                        ),
                        $head['Rate-Limit-Type'] == 'day' ?
                        $this->Translate('1000 calls in 1 day') : $this->Translate('50 calls in 1 minute'),
                        date('d.m.Y H:i:s', $nextRun),
                    ) : sprintf($this->Translate('A rate limit was reached. Requests are blocked until %s.'), date('d.m.Y H:i:s', $nextRun))
                );
                if ($this->GetStatus() != IS_EBASE) {
                    $this->SetStatus(IS_EBASE);
                    IPS_ApplyChanges($this->InstanceID);
                }
                return;

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

        if ($code == 204) {
            $this->ResetRateLimit();
        } else {
            $this->handleHttpErrors($code, $http_response_header);
        }

        return $result;
    }
}
