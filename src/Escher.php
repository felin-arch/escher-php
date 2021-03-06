<?php

class Escher
{
    const DEFAULT_HASH_ALGORITHM = 'SHA256';
    const DEFAULT_ALGO_PREFIX = 'ESR';
    const DEFAULT_VENDOR_KEY = 'Escher';
    const DEFAULT_AUTH_HEADER_KEY = 'X-Escher-Auth';
    const DEFAULT_DATE_HEADER_KEY = 'X-Escher-Date';
    const DEFAULT_CLOCK_SKEW = 300;
    const DEFAULT_EXPIRES = 86400;
    const ISO8601 = 'Ymd\THis\Z';
    const LONG_DATE = self::ISO8601;
    const SHORT_DATE = "Ymd";
    const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    private $credentialScope;
    private $date;
    private $clockSkew = self::DEFAULT_CLOCK_SKEW;
    private $hashAlgo = self::DEFAULT_HASH_ALGORITHM;
    private $algoPrefix = self::DEFAULT_ALGO_PREFIX;
    private $vendorKey = self::DEFAULT_VENDOR_KEY;
    private $authHeaderKey = self::DEFAULT_AUTH_HEADER_KEY;
    private $dateHeaderKey = self::DEFAULT_DATE_HEADER_KEY;

    public function __construct($credentialScope, DateTime $date)
    {
        $this->credentialScope = $credentialScope;
        $this->date = $date;
    }

    public static function create($credentialScope, DateTime $date = null)
    {
        return new Escher($credentialScope, $date ? $date : self::now());
    }

    /**
     * @return DateTime
     */
    private static function now()
    {
        return new DateTime('now', new DateTimeZone('GMT'));
    }

    public function authenticate($keyDB, array $serverVars = null, $requestBody = null)
    {
        $serverVars = null === $serverVars ? $_SERVER : $serverVars;
        $requestBody = null === $requestBody ? $this->fetchRequestBodyFor($serverVars['REQUEST_METHOD']) : $requestBody;

        $algoPrefix = $this->algoPrefix;
        $vendorKey = $this->vendorKey;
        $helper = new EscherRequestHelper($serverVars, $requestBody, $this->authHeaderKey, $this->dateHeaderKey);
        $authElements = $helper->getAuthElements($this->vendorKey, $algoPrefix);

        $authElements->validateMandatorySignedHeaders($this->dateHeaderKey);
        $authElements->validateHashAlgo();
        $authElements->validateDates($helper, $this->clockSkew);
        $authElements->validateCredentials($this->credentialScope);
        $authElements->validateSignature($helper, $this, $keyDB, $vendorKey);
        return $authElements->getAccessKeyId();
    }

    public function presignUrl($accessKeyId, $secretKey, $url, $expires = Escher::DEFAULT_EXPIRES)
    {
        $url = $this->appendSigningParams($accessKeyId, $url, $this->date, $expires);

        list($host, $path, $query) = $this->parseUrl($url);

        $signature = $this->calculateSignature(
            $secretKey,
            $this->date,
            'GET',
            $path,
            $query,
            Escher::UNSIGNED_PAYLOAD,
            array('host' => $host),
            (array('host'))
        );
        $url = $this->addGetParameter($url, $this->generateParamName('Signature'), $signature);

        return $url;
    }

    public function signRequest($accessKeyId, $secretKey, $method, $url, $requestBody, $headerList = array(), $headersToSign = array())
    {
        list($host, $path, $query) = $this->parseUrl($url);
        list($headerList, $headersToSign) = $this->addMandatoryHeaders(
            $headerList, $headersToSign, $this->dateHeaderKey, $this->date, $host
        );

        return $headerList + $this->generateAuthHeader(
            $secretKey,
            $accessKeyId,
            $this->authHeaderKey,
            $this->date,
            $method,
            $path,
            $query,
            $requestBody,
            $headerList,
            $headersToSign
        );
    }

    private function appendSigningParams($accessKeyId, $url, $date, $expires)
    {
        $signingParams = array(
            'Algorithm'     => $this->algoPrefix. '-HMAC-' . $this->hashAlgo,
            'Credentials'   => $accessKeyId . '/' . $this->fullCredentialScope($date),
            'Date'          => $this->toLongDate($date),
            'Expires'       => $expires,
            'SignedHeaders' => 'host',
        );
        foreach ($signingParams as $param => $value)
        {
            $url = $this->addGetParameter($url, $this->generateParamName($param), $value);
        }
        return $url;
    }

    private function generateParamName($param)
    {
        return 'X-' . $this->vendorKey . '-' . $param;
    }

    public function getSignature($secretKey, DateTime $date, $method, $url, $requestBody, $headerList, $signedHeaders)
    {
        list(, $path, $query) = $this->parseUrl($url);
        return $this->calculateSignature($secretKey, $date, $method, $path, $query, $requestBody, $headerList, $signedHeaders);
    }

    private function parseUrl($url)
    {
        $urlParts = parse_url($url);
        $defaultPort = $urlParts['scheme'] === 'http' ? 80 : 443;
        $host = $urlParts['host'] . (isset($urlParts['port']) && $urlParts['port'] != $defaultPort ? ':' . $urlParts['port'] : '');
        $path = isset($urlParts['path']) ? $urlParts['path'] : null;
        $query = isset($urlParts['query']) ? $urlParts['query'] : '';
        return array($host, $path, $query);
    }

    private function toLongDate(DateTime $date)
    {
        return $date->format(Escher::LONG_DATE);
    }

    private function addGetParameter($url, $key, $value)
    {
        $glue = '?';
        if (strpos($url, '?') !== false) {
            $glue = '&';
        }

        $fragmentPosition = strpos($url, '#');
        if ($fragmentPosition === false) {
            return $url . $glue . $key . '=' . urlencode($value);
        }
        else {
            return substr_replace($url, ($glue . $key . '=' . urlencode($value)), $fragmentPosition, 0);
        }
    }

    /**
     * @param $date
     * @return string
     */
    private function fullCredentialScope(DateTime $date)
    {
        return $date->format(Escher::SHORT_DATE) . "/" . $this->credentialScope;
    }

    private function generateAuthHeader($secretKey, $accessKeyId, $authHeaderKey, $date, $method, $path, $query, $requestBody, array $headerList, array $headersToSign)
    {
        $authHeaderValue = EscherSigner::createAuthHeader(
            $this->calculateSignature($secretKey, $date, $method, $path, $query, $requestBody, $headerList, $headersToSign),
            $this->fullCredentialScope($date),
            implode(";", $headersToSign),
            $this->hashAlgo,
            $this->algoPrefix,
            $accessKeyId
        );
        return array(strtolower($authHeaderKey) => $authHeaderValue);
    }

    private function calculateSignature($secretKey, $date, $method, $path, $query, $requestBody, array $headerList, array $headersToSign)
    {
        $hashAlgo = $this->hashAlgo;
        $algoPrefix = $this->algoPrefix;
        $requestUri = $path . ($query ? '?' . $query : '');
        // canonicalization works with raw headers
        $rawHeaderLines = array();
        foreach ($headerList as $headerKey => $headerValue) {
            $rawHeaderLines []= $headerKey . ':' . $headerValue;
        }
        $canonicalizedRequest = EscherRequestCanonicalizer::canonicalize(
            $method,
            $requestUri,
            $requestBody,
            implode("\n", $rawHeaderLines),
            $headersToSign,
            $hashAlgo
        );

        $stringToSign = EscherSigner::createStringToSign(
            $this->credentialScope,
            $canonicalizedRequest,
            $date,
            $hashAlgo,
            $algoPrefix
        );

        $signerKey = EscherSigner::calculateSigningKey(
            $secretKey,
            $this->fullCredentialScope($date),
            $hashAlgo,
            $algoPrefix
        );

        $signature = EscherSigner::createSignature(
            $stringToSign,
            $signerKey,
            $hashAlgo
        );
        return $signature;
    }

    /**
     * @param $headerList
     * @param $headersToSign
     * @param $dateHeaderKey
     * @param $date
     * @param $host
     * @return array
     */
    private function addMandatoryHeaders($headerList, $headersToSign, $dateHeaderKey, $date, $host)
    {
        $mandatoryHeaders = array(strtolower($dateHeaderKey) => $this->toLongDate($date), 'host' => $host);
        $headerList = EscherUtils::keysToLower($headerList) + $mandatoryHeaders;
        $headersToSign = array_unique(array_merge(array_map('strtolower', $headersToSign), array_keys($mandatoryHeaders)));
        sort($headersToSign);
        return array($headerList, $headersToSign);
    }

    /**
     * php://input may contain data even though the request body is empty, e.g. in GET requests
     *
     * @param string
     * @return string
     */
    private function fetchRequestBodyFor($method)
    {
        return in_array($method, array('PUT', 'POST')) ? file_get_contents('php://input') : '';
    }

    /**
     * @param $clockSkew
     * @return Escher
     */
    public function setClockSkew($clockSkew)
    {
        $this->clockSkew = $clockSkew;
        return $this;
    }

    /**
     * @param $hashAlgo
     * @return Escher
     */
    public function setHashAlgo($hashAlgo)
    {
        $this->hashAlgo = strtoupper($hashAlgo);
        return $this;
    }

    /**
     * @param $algoPrefix
     * @return Escher
     */
    public function setAlgoPrefix($algoPrefix)
    {
        $this->algoPrefix = strtoupper($algoPrefix);
        return $this;
    }

    /**
     * @param $vendorKey
     * @return Escher
     */
    public function setVendorKey($vendorKey)
    {
        $this->vendorKey = $vendorKey;
        return $this;
    }

    /**
     * @param $authHeaderKey
     * @return Escher
     */
    public function setAuthHeaderKey($authHeaderKey)
    {
        $this->authHeaderKey = $authHeaderKey;
        return $this;
    }

    /**
     * @param $dateHeaderKey
     * @return Escher
     */
    public function setDateHeaderKey($dateHeaderKey)
    {
        $this->dateHeaderKey = $dateHeaderKey;
        return $this;
    }
}



class EscherRequestHelper
{
    private $serverVars;
    private $requestBody;
    private $authHeaderKey;
    private $dateHeaderKey;

    public function __construct(array $serverVars, $requestBody, $authHeaderKey, $dateHeaderKey)
    {
        $this->serverVars = $serverVars;
        $this->requestBody = $requestBody;
        $this->authHeaderKey = $authHeaderKey;
        $this->dateHeaderKey = $dateHeaderKey;
    }

    public function getRequestMethod()
    {
        return $this->serverVars['REQUEST_METHOD'];
    }

    public function getRequestBody()
    {
        return $this->requestBody;
    }

    public function getAuthElements($vendorKey, $algoPrefix)
    {
        $headerList = EscherUtils::keysToLower($this->getHeaderList());
        $queryParams = $this->getQueryParams();
        if (isset($headerList[strtolower($this->authHeaderKey)])) {
            return EscherAuthElements::parseFromHeaders($headerList, $this->authHeaderKey, $this->dateHeaderKey, $algoPrefix);
        } else if($this->getRequestMethod() === 'GET' && isset($queryParams[$this->paramKey($vendorKey, 'Signature')])) {
            return EscherAuthElements::parseFromQuery($headerList, $queryParams, $vendorKey, $algoPrefix);
        }
        throw new EscherException('Escher authentication is missing');
    }

    public function getTimeStamp()
    {
        return $this->serverVars['REQUEST_TIME'];
    }

    public function getHeaderList()
    {
        $headerList = $this->process($this->serverVars);
        $headerList['content-type'] = $this->getContentType();

        if (strpos($headerList['host'], ':') === false)
        {
            $host = $headerList['host'];
            $port = null;
        }
        else
        {
            list($host, $port) = explode(':', $headerList['host'], 2);
        }
        $headerList['host'] = $this->normalizeHost($host, $port);

        return $headerList;
    }

    public function getCurrentUrl()
    {
        $scheme = (array_key_exists('HTTPS', $this->serverVars) && $this->serverVars["HTTPS"] == "on") ? 'https' : 'http';
        $host = $this->getServerHost();
        $res = "$scheme://$host" . $this->serverVars["REQUEST_URI"];
        return $res;
    }

    private function process(array $serverVars)
    {
        $headerList = array();
        foreach ($serverVars as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $headerList[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        return $headerList;
    }

    private function getContentType()
    {
        return isset($this->serverVars['CONTENT_TYPE']) ? $this->serverVars['CONTENT_TYPE'] : '';
    }

    public function getServerHost()
    {
        return $this->normalizeHost($this->serverVars['SERVER_NAME'], $this->serverVars["SERVER_PORT"]);
    }

    /**
     * @param $vendorKey
     * @param $paramId
     * @return string
     */
    private function paramKey($vendorKey, $paramId)
    {
        return 'X-' . $vendorKey . '-' . $paramId;
    }

    public function getQueryParams()
    {
        list(, $queryString) = array_pad(explode('?', $this->serverVars['REQUEST_URI'], 2), 2, '');
        parse_str($queryString, $result);
        return $result;
    }

    private function normalizeHost($host, $port)
    {
        if (is_null($port) || $this->isDefaultPort($port)) {
            return $host;
        } else {
            return $host . ":" . $port;
        }
    }

    private function isDefaultPort($port)
    {
        $defaultPort = isset($this->serverVars["HTTPS"]) && $this->serverVars["HTTPS"] === "on" ? '443' : '80';
        return $port == $defaultPort;
    }
}


class EscherAuthElements
{
    private $elementParts;

    private $accessKeyId;

    private $shortDate;

    private $credentialScope;

    private $dateTime;

    private $host;

    private $isFromHeaders;

    public function __construct(array $elementParts, $accessKeyId, $shortDate, $credentialScope, DateTime $dateTime, $host, $isFromHeaders)
    {
        $this->elementParts = $elementParts;
        $this->accessKeyId = $accessKeyId;
        $this->shortDate = $shortDate;
        $this->credentialScope = $credentialScope;
        $this->dateTime = $dateTime;
        $this->host = $host;
        $this->isFromHeaders = $isFromHeaders;
    }

    /**
     * @param array $headerList
     * @param $authHeaderKey
     * @param $dateHeaderKey
     * @param $algoPrefix
     * @return EscherAuthElements
     * @throws EscherException
     */
    public static function parseFromHeaders(array $headerList, $authHeaderKey, $dateHeaderKey, $algoPrefix)
    {
        $headerList = EscherUtils::keysToLower($headerList);
        $elementParts = self::parseAuthHeader($headerList[strtolower($authHeaderKey)], $algoPrefix);
        list($accessKeyId, $shortDate, $credentialScope) = explode('/', $elementParts['Credentials'], 3);
        $host = self::checkHost($headerList);

        if (!isset($headerList[strtolower($dateHeaderKey)])) {
            throw new EscherException('The '.strtolower($dateHeaderKey).' header is missing');
        }

        if (strtolower($dateHeaderKey) !== 'date') {
            $dateTime = EscherUtils::parseLongDate($headerList[strtolower($dateHeaderKey)]);
        } else {
            try {
                $dateTime = new DateTime($headerList[strtolower($dateHeaderKey)], new DateTimeZone('GMT'));
            } catch (Exception $ex) {
                throw new EscherException('Date header is invalid, the expected format is Wed, 04 Nov 2015 09:20:22 GMT');
            }
        }
        return new EscherAuthElements($elementParts, $accessKeyId, $shortDate, $credentialScope, $dateTime, $host, true);
    }

    /**
     * @param $headerContent
     * @param $algoPrefix
     * @return array
     * @throws EscherException
     */
    public static function parseAuthHeader($headerContent, $algoPrefix)
    {
        $pattern = '/^' . $algoPrefix . '-HMAC-([A-Z0-9\,]+)(.*)' .
                                     'Credential=([A-Za-z0-9\/\-_]+),(.*)' .
                                     'SignedHeaders=([A-Za-z\-;]+),(.*)' .
                                     'Signature=([0-9a-f]+)$/';

        if (!preg_match($pattern, $headerContent, $matches)) {
            throw new EscherException('Auth header format is invalid');
        }
        return array(
            'Algorithm'     => $matches[1],
            'Credentials'   => $matches[3],
            'SignedHeaders' => $matches[5],
            'Signature'     => $matches[7],
        );
    }

    public static function parseFromQuery($headerList, $queryParams, $vendorKey, $algoPrefix)
    {
        $elementParts = array();
        $paramKey = self::checkParam($queryParams, $vendorKey, 'Algorithm');

        $pattern = '/^' . $algoPrefix . '-HMAC-([A-Z0-9\,]+)$/';
        if (!preg_match($pattern, $queryParams[$paramKey], $matches))
        {
            throw new EscherException('invalid ' . $paramKey . ' query key format');
        }
        $elementParts['Algorithm'] = $matches[1];

        foreach (self::basicQueryParamKeys() as $paramId) {
            $paramKey = self::checkParam($queryParams, $vendorKey, $paramId);
            $elementParts[$paramId] = $queryParams[$paramKey];
        }
        list($accessKeyId, $shortDate, $credentialScope) = explode('/', $elementParts['Credentials'], 3);
        $dateTime = EscherUtils::parseLongDate($elementParts['Date']);
        return new EscherAuthElements($elementParts, $accessKeyId, $shortDate, $credentialScope, $dateTime, self::checkHost($headerList), false);
    }

    private static function basicQueryParamKeys()
    {
        return array(
            'Credentials',
            'Date',
            'Expires',
            'SignedHeaders',
            'Signature'
        );
    }

    /**
     * @param $queryParams
     * @param $vendorKey
     * @param $paramId
     * @return string
     * @throws EscherException
     */
    private static function checkParam($queryParams, $vendorKey, $paramId)
    {
        $paramKey = 'X-' . $vendorKey . '-' . $paramId;
        if (!isset($queryParams[$paramKey])) {
            throw new EscherException('Query key: ' . $paramKey . ' is missing');
        }
        return $paramKey;
    }

    private static function checkHost($headerList)
    {
        if (!isset($headerList['host'])) {
            throw new EscherException('The host header is missing');
        }
        return $headerList['host'];
    }

    public function validateDates(EscherRequestHelper $helper, $clockSkew)
    {
        $shortDate = $this->dateTime->format('Ymd');
        if ($shortDate !== $this->getShortDate()) {
            throw new EscherException('Date in the authorization header is invalid. It must be the same as the date header');
        }

        if (!$this->isInAcceptableInterval($helper->getTimeStamp(), EscherUtils::getTimeStampOfDateTime($this->dateTime), $clockSkew)) {
            throw new EscherException('The request date is not within the accepted time range');
        }
    }

    public function validateCredentials($credentialScope)
    {
        if (!$this->checkCredentials($credentialScope)) {
            throw new EscherException('Credential scope is invalid');
        }
    }

    private function checkCredentials($credentialScope)
    {
        return $this->credentialScope === $credentialScope;
    }

    public function validateSignature(EscherRequestHelper $helper, Escher $escher, $keyDB, $vendorKey)
    {
        $secret = $this->lookupSecretKey($this->accessKeyId, $keyDB);

        $headers = $helper->getHeaderList();
        $calculated = $escher->getSignature(
            $secret,
            $this->dateTime,
            $helper->getRequestMethod(),
            $this->stripAuthParams($helper, $vendorKey),
            $this->isFromHeaders ? $helper->getRequestBody() : Escher::UNSIGNED_PAYLOAD,
            $headers,
            $this->getSignedHeaders()
        );

        $provided = $this->getSignature();
        if ($calculated !== $provided) {
            throw new EscherException("The signatures do not match");
        }
    }

    private function lookupSecretKey($accessKeyId, $keyDB)
    {
        if (!isset($keyDB[$accessKeyId])) {
            throw new EscherException('Invalid Escher key');
        }
        return $keyDB[$accessKeyId];
    }

    public function validateHashAlgo()
    {
        if(!in_array(strtoupper($this->getAlgorithm()), array('SHA256','SHA512')))
        {
            throw new EscherException('Hash algorithm is invalid. Only SHA256 and SHA512 are allowed');
        }
    }

    /**
     * @param string $dateHeaderKey
     * @throws EscherException
     */
    public function validateMandatorySignedHeaders($dateHeaderKey)
    {
        $signedHeaders = $this->getSignedHeaders();
        if (!in_array('host', $signedHeaders)) {
            throw new EscherException('The host header is not signed');
        }
        if ($this->isFromHeaders && !in_array(strtolower($dateHeaderKey), $signedHeaders)) {
            throw new EscherException('The ' . strtolower($dateHeaderKey) . ' header is not signed');
        }
    }

    public function getAccessKeyId()
    {
        return $this->accessKeyId;
    }

    public function getShortDate()
    {
        return $this->shortDate;
    }

    public function getSignedHeaders()
    {
        return explode(';', $this->elementParts['SignedHeaders']);
    }

    public function getSignature()
    {
        return $this->elementParts['Signature'];
    }

    public function getAlgorithm()
    {
        return $this->elementParts['Algorithm'];
    }

    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param EscherRequestHelper $helper
     * @param $vendorKey
     * @return string
     */
    private function stripAuthParams(EscherRequestHelper $helper, $vendorKey)
    {
        $url = $helper->getCurrentUrl();
        $signaturePattern = "/(?P<prefix>[?&])X-${vendorKey}-Signature=[a-fA-F0-9]{64}(?P<suffix>&?)/";

        return preg_replace_callback($signaturePattern, array($this, 'handleStripAuthParamMatches'), $url);
    }

    private function getExpires()
    {
        return $this->isFromHeaders ? 0 : $this->elementParts['Expires'];
    }

    private function isInAcceptableInterval($currentTimeStamp, $requestTimeStamp, $clockSkew)
    {
        return ($requestTimeStamp - $clockSkew <= $currentTimeStamp)
            && ($currentTimeStamp <= $requestTimeStamp + $this->getExpires() + $clockSkew);
    }

    public function getCredentialScope()
    {
        return $this->credentialScope;
    }

    public function getDateTime()
    {
        return $this->dateTime;
    }

    private function handleStripAuthParamMatches($matches) {
        return (!empty($matches['suffix']) || $matches['prefix'] === '?')
            ? $matches['prefix']
            : $matches['suffix'];
    }
}



class EscherException extends Exception
{
}



class EscherRequestCanonicalizer
{
    public static function canonicalize($method, $requestUri, $payload, $rawHeaders, array $headersToSign, $hashAlgo)
    {
        list($path, $query) = array_pad(explode('?', $requestUri, 2), 2, '');
        $lines = array();
        $lines[] = strtoupper($method);
        $lines[] = self::normalizePath($path);
        $lines[] = self::urlEncodeQueryString($query);

        sort($headersToSign);
        $lines = array_merge($lines, self::canonicalizeHeaders($rawHeaders, $headersToSign));

        $lines[] = '';
        $lines[] = implode(";", $headersToSign);

        $lines[] = hash($hashAlgo, $payload);

        return implode("\n", $lines);
    }

    public static function urlEncodeQueryString($query)
    {
        if (empty($query)) return "";
        $pairs = explode("&", $query);
        $encodedParts = array();
        foreach ($pairs as $pair) {
            $keyValues = array_pad(explode("=", $pair), 2, '');
            if (strpos($keyValues[0], " ") !== false) {
                $keyValues[0] = substr($keyValues[0], 0, strpos($keyValues[0], " "));
                $keyValues[1] = "";
            }
            $keyValues[0] = urldecode($keyValues[0]);
            $keyValues[1] = urldecode($keyValues[1]);
            $encodedParts[] = implode("=", array(
                self::rawUrlEncode(str_replace('+', ' ', $keyValues[0])),
                self::rawUrlEncode(str_replace('+', ' ', $keyValues[1])),
            ));
        }
        sort($encodedParts);
        return implode("&", $encodedParts);
    }

    private static function normalizePath($path)
    {
        $path = explode('/', $path);
        $keys = array_keys($path, '..');

        foreach($keys as $keypos => $key)
        {
            array_splice($path, $key - ($keypos * 2 + 1), 2);
        }

        $path = implode('/', $path);
        $path = str_replace('./', '', $path);

        $path = str_replace("//", "/", $path);

        if (empty($path)) return "/";
        return $path;
    }

    /**
     * @param $rawHeaders
     * @param $headersToSign
     * @return array
     */
    private static function canonicalizeHeaders($rawHeaders, array $headersToSign)
    {
        $result = array();
        foreach (explode("\n", $rawHeaders) as $header) {
            // TODO: add multiline header handling
            list ($key, $value) = explode(':', $header, 2);
            $lowerKey = strtolower($key);
            $trimmedValue = self::nomalizeHeaderValue($value);
            if (!in_array($lowerKey, $headersToSign)) {
                continue;
            }
            if (isset($result[$lowerKey])) {
                $result[$lowerKey] .= ',' . $trimmedValue;
            } else {
                $result[$lowerKey] =  $lowerKey . ':' . $trimmedValue;
            }
        }
        sort($result);
        return $result;
    }

    private static function rawUrlEncode($urlComponent)
    {
        $result = rawurlencode($urlComponent);
        if (version_compare(PHP_VERSION, '5.3.4') === -1) {
            $result = str_replace('%7E', '~', $result);
        }
        return $result;
    }

    /**
     * @param $value
     * @return string
     */
    private static function nomalizeHeaderValue($value)
    {
        $result = array();
        foreach (explode('"', trim($value)) as $index => $piece) {
            $result[] = $index % 2 === 1 ? $piece : preg_replace('/\s+/', ' ', $piece);
        }
        return implode('"', $result);
    }
}



class EscherSigner
{
    public static function createStringToSign($credentialScope, $canonicalRequestString, DateTime $date, $hashAlgo, $algoPrefix)
    {
        $date = clone $date;
        $date->setTimezone(new DateTimeZone("GMT"));
        $formattedDate = $date->format(Escher::LONG_DATE);
        $scope = substr($formattedDate,0, 8) . "/" . $credentialScope;
        $lines = array();
        $lines[] = $algoPrefix . "-HMAC-" . strtoupper($hashAlgo);
        $lines[] = $formattedDate;
        $lines[] = $scope;
        $lines[] = hash($hashAlgo, $canonicalRequestString);
        return implode("\n", $lines);
    }

    public static function calculateSigningKey($secret, $credentialScope, $hashAlgo, $algoPrefix)
    {
        $key = $algoPrefix . $secret;
        $credentials = explode("/", $credentialScope);
        foreach ($credentials as $data) {
            $key = hash_hmac($hashAlgo, $data, $key, true);
        }
        return $key;
    }

    public static function createAuthHeader($signature, $credentialScope, $signedHeaders, $hashAlgo, $algoPrefix, $accessKey)
    {
        return $algoPrefix . "-HMAC-" . strtoupper($hashAlgo)
        . " Credential="
        . $accessKey . "/"
        . $credentialScope
        . ", SignedHeaders=" . $signedHeaders
        . ", Signature=" . $signature;
    }

    public static function createSignature($stringToSign, $signerKey, $hashAlgo)
    {
        return hash_hmac($hashAlgo, $stringToSign, $signerKey);
    }
}

class EscherUtils
{
    public static function parseLongDate($dateString)
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $dateString)) {
            throw new EscherException('Date header is invalid, the expected format is 20151104T092022Z');
        }
        if (!self::advancedDateTimeFunctionsAvailable()) {
            return new DateTime($dateString, new DateTimeZone('GMT'));
        }
        return DateTime::createFromFormat('Ymd\THisT', $dateString, new DateTimeZone('GMT'));
    }

    public static function keysToLower($array)
    {
        if (count($array) === 0)
        {
            return array();
        }
        return array_combine(
            array_map('strtolower', array_keys($array)),
            array_values($array)
        );
    }

    public static function getTimeStampOfDateTime($dateTime)
    {
        if (!self::advancedDateTimeFunctionsAvailable()) {
            return $dateTime->format('U');
        }
        return $dateTime->getTimestamp();
    }

    /**
     * @return bool
     */
    protected static function advancedDateTimeFunctionsAvailable()
    {
        return version_compare(PHP_VERSION, '5.3.0') !== -1;
    }
}
