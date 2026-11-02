<?php

declare(strict_types=1);

if (defined('PHPUNIT_TESTSUITE')) {
    trait TestAPI
    {
        public $selectedProgram = '';

        //Endpoint => fixture file name (without .json) used instead of response.json. Lets a
        //test answer an endpoint the way the appliance does in another operation state.
        public $responseVariants = [];

        //All PUT requests as [endpoint, payload], so tests can inspect e.g. the start payload.
        public $putRequests = [];

        //Counts API GET requests so tests can assert that no retry loop burns the rate limit.
        public static $requestCount = 0;

        public function getRequest(string $endpoint)
        {
            self::$requestCount++;
            preg_match('/homeappliances\/.+\/programs\/selected/', $endpoint, $matches);
            if ($matches) {
                return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/' . $this->selectedProgram . '.json');
            }
            if (isset($this->responseVariants[$endpoint])) {
                return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/' . $this->responseVariants[$endpoint] . '.json');
            }
            return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/response.json');
        }

        public function putRequest(string $endpoint, string $payload)
        {
            $this->putRequests[] = [$endpoint, $payload];
            preg_match('/homeappliances\/.+\/programs\/selected/', $endpoint, $matches);
            if ($matches) {
                preg_match('/.+\.(.+)/m', json_decode($payload, true)['data']['key'], $matches);
                if ($matches) {
                    $this->selectedProgram = $matches[1];
                }
                return '';
            }
            return '';
        }

        public function retrieveAccessToken()
        {
        }
    }
} else {
    trait TestAPI
    {
        public function getRequest(string $endpoint)
        {
            return $this->getData($endpoint);
        }

        public function putRequest(string $endpoint, string $payload)
        {
            return $this->putData($endpoint, $payload);
        }

        public function deleteRequest(string $endpoint)
        {
            return $this->deleteData($endpoint);
        }
    }
}