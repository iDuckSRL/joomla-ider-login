<?php

/**
 *
 * Copyright IDER 2017
 *
 * IDEROpenIDConnectClient for PHP5
 * Author: Davide Lattanzio <info@dualweb.it>
 *
 * Based on OpenIDConnectClient for PHP5 by Michael Jett
 *
 */

namespace IDERConnect;

/**
 *
 * Please note this class stores nonces in $_SESSION['openid_connect_nonce']
 *
 */
class IDEROpenIDClient
{

    /**
     * Overridable base URL.
     */
    static $BaseUrl;

    /**
     * @var string Last Instance
     */
    static $IDERServer = 'https://oid.ider.com/core';

    /**
     * @var string IDER server
     */
    static $_instance;

    /**
     * @var string IDER server
     */
    static $defaultScope = 'openid';

    /**
     * @var string IDER server
     */
    static $IDERButtonURL = 'iderbutton';

    /**
     * @var string IDER server
     */
    static $IDERRedirectURL = 'idercallback';

    /**
     * @var string IDER server
     */
    static $IDERLogFile = './log/ider-connect.log';

    /**
     * @var string arbitrary id value
     */
    private $clientID;

    /*
     * @var string arbitrary name value
     */
    private $clientName;

    /**
     * @var string arbitrary secret value
     */
    private $clientSecret;

    /**
     * @var array holds the provider configuration
     */
    private $providerConfig = array();

    /**
     * @var string http proxy if necessary
     */
    private $httpProxy;

    /**
     * @var string full system path to the SSL certificate
     */
    private $certPath;

    /**
     * @var string if we aquire an access token it will be stored here
     */
    private $accessToken;

    /**
     * @var string if we aquire a refresh token it will be stored here
     */
    private $refreshToken;

    /**
     * @var string if we acquire an id token it will be stored here
     */
    private $idToken;

    /**
     * @var string base URL
     */
    private $baseUrl;

    /**
     * @var array holds scopes
     */
    private $scopes = array();

    /**
     * @var array holds response types
     */
    private $responseTypes = array();

    /**
     * @var array holds a cache of info returned from the user info endpoint
     */
    private $userInfo = array();

    /**
     * @var array holds authentication parameters
     */
    private $authParams = array();

    /**
     * @param $provider_url string optional
     *
     * @param $client_id string
     * @param $client_secret string
     *
     */
    public function __construct($client_id, $client_secret, $scopes = null)
    {
        IDERHelpers::logRotate('======= IDer boot ======', static::$IDERLogFile);
        IDERHelpers::logRotate('Called url: ' . $this->getRedirectURL(false), static::$IDERLogFile);


        $this->setProviderURL(static::$IDERServer);
        $this->setRedirectURL($this->getBaseUrl() . static::$IDERRedirectURL);
        $this->setClientID($client_id);
        $this->setClientSecret($client_secret);

        $this->boot();

        // set scope to default
        $this->resetScopes();

        if (!is_null($scopes)) {
            $this->addScope($scopes);
        }


        IDERHelpers::logRotate('Booted', static::$IDERLogFile);
    }

    private function boot()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        IDERHelpers::logRotate('Session start', static::$IDERLogFile);


        /**
         * Require the CURL and JSON PHP extentions to be installed
         */
        if (!function_exists('curl_init')) {
            throw new OpenIDConnectClientException('OpenIDConnect needs the CURL PHP extension.');
        }
        if (!function_exists('json_decode')) {
            throw new OpenIDConnectClientException('OpenIDConnect needs the JSON PHP extension.');
        }

        /**
         *
         * JWT signature verification support by Jonathan Reed <jdreed@mit.edu>
         * Licensed under the same license as the rest of this file.
         *
         * phpseclib is required to validate the signatures of some tokens.
         * It can be downloaded from: http://phpseclib.sourceforge.net/
         */
        if (!class_exists('\phpseclib\Crypt\RSA')) {
            user_error('Unable to find phpseclib Crypt/RSA.php.  Ensure phpseclib is installed and in include_path');
        }

        IDERHelpers::logRotate('Libraries check passed', static::$IDERLogFile);

    }

    /**
     * @param $provider_url
     */
    public function setProviderURL($provider_url)
    {
        $this->providerConfig['issuer'] = $provider_url;
    }

    /**
     * @param $response_types
     */
    public function setResponseTypes($response_types)
    {
        $this->responseTypes = array_merge($this->responseTypes, (array)$response_types);
    }

    /**
     * @return bool
     * @throws OpenIDConnectClientException
     */
    public function authenticate()
    {
        IDERHelpers::logRotate('autenticate()', static::$IDERLogFile);


        // Do a preemptive check to see if the provider has thrown an error from a previous redirect
        if (isset($_REQUEST['error'])) {
            throw new OpenIDConnectClientException("Error: " . $_REQUEST['error'] . " Description: " . $_REQUEST['error_description']);
        }

        // If we have an authorization code then proceed to request a token
        if (isset($_REQUEST["code"])) {
            IDERHelpers::logRotate('Request code IS set', static::$IDERLogFile);

            $code = $_REQUEST["code"];
            $token_json = $this->requestTokens($code);

            // Throw an error if the server returns one
            if (isset($token_json->error)) {
                if (isset($token_json->error_description)) {
                    throw new OpenIDConnectClientException($token_json->error_description);
                }
                throw new OpenIDConnectClientException('Got response: ' . $token_json->error);
            }

            // Do an OpenID Connect session check
            if ($_REQUEST['state'] != $_SESSION['openid_connect_state']) {
                throw new OpenIDConnectClientException("Unable to determine state");
            }

            if (!property_exists($token_json, 'id_token')) {
                throw new OpenIDConnectClientException("User did not authorize openid scope.");
            }

            $claims = $this->decodeJWT($token_json->id_token, 1);

            // Verify the signature
            if ($this->canVerifySignatures()) {
                if (!$this->verifyJWTsignature($token_json->id_token)) {
                    throw new OpenIDConnectClientException ("Unable to verify signature");
                }
            } else {
                IDERHelpers::logRotate('Warning: JWT signature verification unavailable.', static::$IDERLogFile);

                user_error("Warning: JWT signature verification unavailable.");
            }

            // If this is a valid claim
            if ($this->verifyJWTclaims($claims)) {
                IDERHelpers::logRotate('JWTclaims verified', static::$IDERLogFile);

                // Clean up the session a little
                unset($_SESSION['openid_connect_nonce']);

                // Save the id token
                $this->idToken = $token_json->id_token;

                // Save the access token
                $this->accessToken = $token_json->access_token;

                // Save the refresh token, if we got one
                if (isset($token_json->refresh_token)) $this->refreshToken = $token_json->refresh_token;

                // Success!
                return true;

            } else {
                throw new OpenIDConnectClientException ("Unable to verify JWT claims");
            }

        } else {
            IDERHelpers::logRotate('Request code IS NOT set', static::$IDERLogFile);

            $this->requestAuthorization();
            return false;
        }

    }

    /**
     * @param $scope - example: openid, given_name, etc...
     */
    public function addScope($scope)
    {
        $this->scopes = array_merge($this->scopes, (array)$scope);
        IDERHelpers::logRotate('Scope set to: ' . implode(' ', $this->scopes), static::$IDERLogFile);
    }

    /**
     * @param $scope
     */
    public function resetScopes($addDefault = true)
    {
        if ($addDefault && !is_null(self::$defaultScope)) {
            $this->scopes = [self::$defaultScope];
        }
        IDERHelpers::logRotate('Scope reset to: ' . implode(' ', $this->scopes), static::$IDERLogFile);
    }

    /**
     * @param $scope - example: openid, given_name, etc...
     */
    public function setScope($scope, $addDefault = true)
    {
        $this->resetScopes($addDefault);
        $this->addScope($scope);
        IDERHelpers::logRotate('Scope set to: ' . implode(' ', $this->scopes), static::$IDERLogFile);
    }

    /**
     * @param $param - example: prompt=login
     */
    public function addAuthParam($param)
    {
        $this->authParams = array_merge($this->authParams, (array)$param);
    }

    /**
     * Get's anything that we need configuration wise including endpoints, and other values
     *
     * @param $param
     * @throws OpenIDConnectClientException
     * @return string
     *
     */
    private function getProviderConfigValue($param)
    {
        IDERHelpers::logRotate('getProviderConfigValue()', static::$IDERLogFile);

        // If the configuration value is not available, attempt to fetch it from a well known config endpoint
        // This is also known as auto "discovery"
        if (!isset($this->providerConfig[$param])) {
            $well_known_config_url = rtrim($this->getProviderURL(), "/") . "/.well-known/openid-configuration";
            $this->providerConfig = (array)json_decode($this->fetchURL($well_known_config_url));

            IDERHelpers::logRotate('Discovery: ' . print_r($this->providerConfig, 1), static::$IDERLogFile);
        }
        
        if (!isset($this->providerConfig[$param])) {
            throw new OpenIDConnectClientException("The provider {$param} has not been set. Make sure your provider has a well known configuration available.");
        }

        return $this->providerConfig[$param];
    }


    /**
     * @param $url Sets redirect URL for auth flow
     */
    public function setRedirectURL($url)
    {
        IDERHelpers::logRotate('Set redirect url: ' . $url, static::$IDERLogFile);

        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            $this->redirectURL = $url;
        }
    }

    /**
     * Gets the URL of the current page we are on, encodes, and returns it
     *
     * @return string
     */
    public function getRedirectURL($overwritten = true)
    {

        // If the redirect URL has been set then return it.
        if (property_exists($this, 'redirectURL') && $this->redirectURL) {
            return $this->redirectURL;
        }

        // Other-wise return the URL of the current page
        $currentUrl = $this->getBaseUrl($overwritten) . substr($_SERVER['REQUEST_URI'], 1);

        return $currentUrl;
    }


    /**
     * Used for arbitrary value generation for nonces and state
     *
     * @return string
     */
    protected function getBaseUrl($overwritten = true)
    {

        // If the base URL is set, then use it.
        if(static::$BaseUrl && $overwritten){
            return rtrim(static::$BaseUrl, '/') . '/';
        }

        /**
         * Thank you
         * http://stackoverflow.com/questions/189113/how-do-i-get-current-page-full-url-in-php-on-a-windows-iis-server
         */

        /**
         * Compatibility with multiple host headers.
         * The problem with SSL over port 80 is resolved and non-SSL over port 443.
         * Support of 'ProxyReverse' configurations.
         */

        $protocol = null;
        $port = null;
        $hostname = null;
        $setport = null;

        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        } else if (isset($_SERVER['REQUEST_SCHEME'])) {
            $protocol = $_SERVER['REQUEST_SCHEME'];
        } else if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
            $protocol = "https";
        } else {
            $protocol = "http";
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = intval($_SERVER['HTTP_X_FORWARDED_PORT']);
        } else if (isset($_SERVER["SERVER_PORT"])) {
            $port = intval($_SERVER["SERVER_PORT"]);
        } else if ($protocol === 'https') {
            $port = 443;
        } else {
            $port = 80;
        }
        if (isset($_SERVER['HTTP_HOST'])) {
            $hostname = $_SERVER['HTTP_HOST'];
        } else if (isset($_SERVER['SERVER_NAME'])) {
            $hostname = $_SERVER['SERVER_NAME'];
        } else if (isset($_SERVER['SERVER_ADDR'])) {
            $hostname = $_SERVER['SERVER_ADDR'];
        }

        $hostname = preg_replace('/:[0-9]+/', '', $hostname);

        $useport = ($protocol === 'https' && $port !== 443) || ($protocol === 'http' && $port !== 80);

        $base_page_url = $protocol . '://' . $hostname . ($useport ? (':' . $port) : '');

        return rtrim($base_page_url, '/') . '/';

    }


    /**
     * Used for arbitrary value generation for nonces and state
     *
     * @return string
     */
    protected function generateRandString()
    {
        return md5(uniqid(rand(), TRUE));
    }

    /**
     * Start Here
     * @return void
     */
    private function requestAuthorization()
    {
        IDERHelpers::logRotate('requestAuthorization()', static::$IDERLogFile);

        $auth_endpoint = $this->getProviderConfigValue("authorization_endpoint");
        $response_type = "code";
        
        // Save scope for future porpoise
        $_SESSION['openid_connect_scope'] = $this->scopes;

        // Generate and store a nonce in the session
        // The nonce is an arbitrary value
        $nonce = $this->generateRandString();
        $_SESSION['openid_connect_nonce'] = $nonce;

        // State essentially acts as a session key for OIDC
        $state = $this->generateRandString();
        $_SESSION['openid_connect_state'] = $state;

        IDERHelpers::logRotate('set state: ' . $state, static::$IDERLogFile);
        IDERHelpers::logRotate('set nonce: ' . $nonce, static::$IDERLogFile);


        $auth_params = array_merge($this->authParams, array(
            'response_type' => $response_type,
//            'response_mode' => "form_post",
            'redirect_uri' => $this->getRedirectURL(),
            'client_id' => $this->getClientID(),
            'nonce' => $nonce,
            'state' => $state,
            'scope' => 'openid'
        ));

        // If the client has been registered with additional scopes
        if (sizeof($this->scopes) > 0) {
            $auth_params = array_merge($auth_params, array('scope' => implode(' ', $this->scopes)));
        }

        // If the client has been registered with additional response types
        if (sizeof($this->responseTypes) > 0) {
            $auth_params = array_merge($auth_params, array('response_type' => implode(' ', $this->responseTypes)));
        }

        $auth_endpoint .= '?' . http_build_query($auth_params, null, '&');

        session_commit();
        $this->redirect($auth_endpoint);
    }


    /**
     * Requests ID and Access tokens
     *
     * @param $code
     * @return mixed
     */
    private function requestTokens($code)
    {
        IDERHelpers::logRotate('requestToken()', static::$IDERLogFile);

        $token_endpoint = $this->getProviderConfigValue("token_endpoint");
        $token_endpoint_auth_methods_supported = $this->getProviderConfigValue("token_endpoint_auth_methods_supported");

        $headers = [];

        $grant_type = "authorization_code";

        $token_params = array(
            'grant_type' => $grant_type,
            'code' => $code,
            'redirect_uri' => $this->getRedirectURL(),
            'client_id' => $this->clientID,
            'client_secret' => $this->clientSecret
        );

        # Consider Basic authentication if provider config is set this way
        if (in_array('client_secret_basic', $token_endpoint_auth_methods_supported)) {
            $headers = ['Authorization: Basic ' . base64_encode($this->clientID . ':' . $this->clientSecret)];
            unset($token_params['client_secret']);
        }

        // Convert token params to string format
        $token_params = http_build_query($token_params, null, '&');

        return json_decode($this->fetchURL($token_endpoint, $token_params, $headers));

    }

    /**
     * Requests Access token with refresh token
     *
     * @param $code
     * @return mixed
     */
    public function refreshToken($refresh_token)
    {
        $token_endpoint = $this->getProviderConfigValue("token_endpoint");

        $grant_type = "refresh_token";

        $token_params = array(
            'grant_type' => $grant_type,
            'refresh_token' => $refresh_token,
            'client_id' => $this->clientID,
            'client_secret' => $this->clientSecret,
        );

        // Convert token params to string format
        $token_params = http_build_query($token_params, null, '&');

        $json = json_decode($this->fetchURL($token_endpoint, $token_params));
        $this->refreshToken = $json->refresh_token;

        return $json;
    }

    /**
     * @param array $keys
     * @param array $header
     * @throws OpenIDConnectClientException
     * @return object
     */
    private function get_key_for_header($keys, $header)
    {
        foreach ($keys as $key) {
            if ((!(isset($key->alg) && isset($header->kid)) && $key->kty == 'RSA') || ($key->alg == $header->alg && $key->kid == $header->kid)) {
                return $key;
            }
        }
        if (isset($header->kid)) {
            throw new OpenIDConnectClientException('Unable to find a key for (algorithm, kid):' . $header->alg . ', ' . $header->kid . ')');
        } else {
            throw new OpenIDConnectClientException('Unable to find a key for RSA');
        }
    }


    /**
     * @param string $hashtype
     * @param object $key
     * @throws OpenIDConnectClientException
     * @return bool
     */
    private function verifyRSAJWTsignature($hashtype, $key, $payload, $signature)
    {
        IDERHelpers::logRotate('verifyRSAJWTsignature()', static::$IDERLogFile);

        if (!class_exists('\phpseclib\Crypt\RSA')) {
            throw new OpenIDConnectClientException('Crypt_RSA support unavailable.');
        }
        if (!(property_exists($key, 'n') and property_exists($key, 'e'))) {
            throw new OpenIDConnectClientException('Malformed key object');
        }

        /* We already have base64url-encoded data, so re-encode it as
           regular base64 and use the XML key format for simplicity.
        */
        $public_key_xml = "<RSAKeyValue>\r\n" .
            "  <Modulus>" . IDERHelpers::b64url2b64($key->n) . "</Modulus>\r\n" .
            "  <Exponent>" . IDERHelpers::b64url2b64($key->e) . "</Exponent>\r\n" .
            "</RSAKeyValue>";
        $rsa = new \phpseclib\Crypt\RSA();
        $rsa->setHash($hashtype);
        $rsa->loadKey($public_key_xml, \phpseclib\Crypt\RSA::PUBLIC_FORMAT_XML);
        $rsa->signatureMode = \phpseclib\Crypt\RSA::SIGNATURE_PKCS1;
        return $rsa->verify($payload, $signature);
    }

    /**
     * @param $jwt string encoded JWT
     * @throws OpenIDConnectClientException
     * @return bool
     */
    private function verifyJWTsignature($jwt)
    {
        IDERHelpers::logRotate('verifyJWTsignature()', static::$IDERLogFile);

        $parts = explode(".", $jwt);
        $signature = IDERHelpers::base64url_decode(array_pop($parts));
        $header = json_decode(IDERHelpers::base64url_decode($parts[0]));
        $payload = implode(".", $parts);
        $jwks = json_decode($this->fetchURL($this->getProviderConfigValue('jwks_uri')));
        if ($jwks === NULL) {
            throw new OpenIDConnectClientException('Error decoding JSON from jwks_uri');
        }
        $verified = false;
        switch ($header->alg) {
            case 'RS256':
            case 'RS384':
            case 'RS512':
                $hashtype = 'sha' . substr($header->alg, 2);

                $verified = $this->verifyRSAJWTsignature($hashtype,
                    $this->get_key_for_header($jwks->keys, $header),
                    $payload, $signature);

                IDERHelpers::logRotate('Signature verification: ' . ($verified == true ? 'ok' : 'err'), static::$IDERLogFile);

                break;
            default:
                throw new OpenIDConnectClientException('No support for signature type: ' . $header->alg);
        }
        return $verified;
    }

    /**
     * @param object $claims
     * @return bool
     */
    private function verifyJWTclaims($claims)
    {
        IDERHelpers::logRotate('verifyJWTclaims()', static::$IDERLogFile);

        return (($claims->exp > gmdate('U')) && ($claims->iss == $this->getProviderURL())
            && (($claims->aud == $this->clientID) || (in_array($this->clientID, $claims->aud)))
            && ($claims->nonce == $_SESSION['openid_connect_nonce']));

    }

    /**
     * @param $jwt string encoded JWT
     * @param int $section the section we would like to decode
     * @return object
     */
    private function decodeJWT($jwt, $section = 0)
    {
        IDERHelpers::logRotate('decodeJWT()', static::$IDERLogFile);

        $parts = explode(".", $jwt);
        return json_decode(IDERHelpers::base64url_decode($parts[$section]));
    }

    /**
     *
     * @param $attribute string optional
     *
     * Attribute        Type    Description
     * user_id            string    REQUIRED Identifier for the End-User at the Issuer.
     * name            string    End-User's full name in displayable form including all name parts, ordered according to End-User's locale and preferences.
     * given_name        string    Given name or first name of the End-User.
     * family_name        string    Surname or last name of the End-User.
     * middle_name        string    Middle name of the End-User.
     * nickname        string    Casual name of the End-User that may or may not be the same as the given_name. For instance, a nickname value of Mike might be returned alongside a given_name value of Michael.
     * profile            string    URL of End-User's profile page.
     * picture            string    URL of the End-User's profile picture.
     * website            string    URL of End-User's web page or blog.
     * email            string    The End-User's preferred e-mail address.
     * verified        boolean    True if the End-User's e-mail address has been verified; otherwise false.
     * gender            string    The End-User's gender: Values defined by this specification are female and male. Other values MAY be used when neither of the defined values are applicable.
     * birthday        string    The End-User's birthday, represented as a date string in MM/DD/YYYY format. The year MAY be 0000, indicating that it is omitted.
     * zoneinfo        string    String from zoneinfo [zoneinfo] time zone database. For example, Europe/Paris or America/Los_Angeles.
     * locale            string    The End-User's locale, represented as a BCP47 [RFC5646] language tag. This is typically an ISO 639-1 Alpha-2 [ISO639‑1] language code in lowercase and an ISO 3166-1 Alpha-2 [ISO3166‑1] country code in uppercase, separated by a dash. For example, en-US or fr-CA. As a compatibility note, some implementations have used an underscore as the separator rather than a dash, for example, en_US; Implementations MAY choose to accept this locale syntax as well.
     * phone_number    string    The End-User's preferred telephone number. E.164 [E.164] is RECOMMENDED as the format of this Claim. For example, +1 (425) 555-1212 or +56 (2) 687 2400.
     * address            JSON object    The End-User's preferred address. The value of the address member is a JSON [RFC4627] structure containing some or all of the members defined in Section 2.4.2.1.
     * updated_time    string    Time the End-User's information was last updated, represented as a RFC 3339 [RFC3339] datetime. For example, 2011-01-03T23:58:42+0000.
     *
     * @return mixed
     *
     */
    public function requestUserInfo($attribute = null)
    {
        IDERHelpers::logRotate('requestUserInfo()', static::$IDERLogFile);

        if (empty($this->userInfo)) {
            $user_info_endpoint = $this->getProviderConfigValue("userinfo_endpoint");
            $schema = 'openid';

            $user_info_endpoint .= "?schema=" . $schema;

            //The accessToken has to be send in the Authorization header, so we create a new array with only this header.
            $headers = array("Authorization: Bearer {$this->accessToken}");

            $user_json = json_decode($this->fetchURL($user_info_endpoint, null, $headers));

            $this->userInfo = $user_json;

            IDERHelpers::logRotate('Request: ' . print_r($this->userInfo, 1), static::$IDERLogFile);

        }

        if ($attribute === null) {
            return $this->userInfo;
        } else if (array_key_exists($attribute, $this->userInfo)) {
            return $this->userInfo->$attribute;
        } else {
            return null;
        }
    }

    /**
     * @param $url
     * @param null $post_body string If this is set the post type will be POST
     * @param array() $headers Extra headers to be send with the request. Format as 'NameHeader: ValueHeader'
     * @throws OpenIDConnectClientException
     * @return mixed
     */
    protected function fetchURL($url, $post_body = null, $headers = array())
    {
        IDERHelpers::logRotate('fetchURL(): ' . $url, static::$IDERLogFile);
        IDERHelpers::logRotate('Request headers: ' . print_r($headers, 1), static::$IDERLogFile);
        IDERHelpers::logRotate('Request body: ' . print_r($post_body, 1), static::$IDERLogFile);


        // OK cool - then let's create a new cURL resource handle
        $ch = curl_init();

        // Determine whether this is a GET or POST
        if ($post_body != null) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);

            // Default content type is form encoded
            $content_type = 'application/x-www-form-urlencoded';

            // Determine if this is a JSON payload and add the appropriate content type
            if (is_object(json_decode($post_body))) {
                $content_type = 'application/json';
            }

            // Add POST-specific headers
            $headers[] = "Content-Type: {$content_type}";
            $headers[] = 'Content-Length: ' . strlen($post_body);

        }

        // If we set some headers include them
        if (count($headers) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Set URL to download
        curl_setopt($ch, CURLOPT_URL, $url);

        if (isset($this->httpProxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->httpProxy);
        }

        // Include header in result? (0 = yes, 1 = no)
        curl_setopt($ch, CURLOPT_HEADER, 0);

        /**
         * Set cert
         * Otherwise ignore SSL peer verification
         */
        if (isset($this->certPath)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $this->certPath);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        }

        // Should cURL return or print out the data? (true = return, false = print)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Timeout in seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // Force IPV4
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );

        // Download the given URL, and return output
        $output = curl_exec($ch);

        if ($output === false) {
            throw new OpenIDConnectClientException('Curl error: ' . curl_error($ch));
        }

        // Close the cURL resource, and free system resources
        curl_close($ch);

        IDERHelpers::logRotate('Response: ' . print_r($output, 1), static::$IDERLogFile);
        return $output;
    }

    /**
     * @return string
     * @throws OpenIDConnectClientException
     */
    public function getProviderURL()
    {

        if (!isset($this->providerConfig['issuer'])) {
            throw new OpenIDConnectClientException("The provider URL has not been set");
        } else {
            return $this->providerConfig['issuer'];
        }
    }

    /**
     * @param $url
     */
    public function redirect($url)
    {
        IDERHelpers::logRotate('Redirect to: ' . $url, static::$IDERLogFile);

        header('Location: ' . $url);
        exit;
    }

    /**
     * @param $httpProxy
     */
    public function setHttpProxy($httpProxy)
    {
        $this->httpProxy = $httpProxy;
    }

    /**
     * @param $certPath
     */
    public function setCertPath($certPath)
    {
        $this->certPath = $certPath;
    }

    /**
     *
     * Use this to alter a provider's endpoints and other attributes
     *
     * @param $array
     *        simple key => value
     */
    public function providerConfigParam($array)
    {
        $this->providerConfig = array_merge($this->providerConfig, $array);
    }

    /**
     * @param $clientSecret
     */
    public function setClientSecret($clientSecret)
    {
        $this->clientSecret = $clientSecret;
    }

    /**
     * @param $clientID
     */
    public function setClientID($clientID)
    {
        $this->clientID = $clientID;
    }


    /**
     * Dynamic registration
     *
     * @throws OpenIDConnectClientException
     */
    public function register()
    {

        $registration_endpoint = $this->getProviderConfigValue('registration_endpoint');

        $send_object = (object)array(
            'redirect_uris' => array($this->getRedirectURL()),
            'client_name' => $this->getClientName()
        );

        $response = $this->fetchURL($registration_endpoint, json_encode($send_object));

        $json_response = json_decode($response);

        // Throw some errors if we encounter them
        if ($json_response === false) {
            throw new OpenIDConnectClientException("Error registering: JSON response received from the server was invalid.");
        } elseif (isset($json_response->{'error_description'})) {
            throw new OpenIDConnectClientException($json_response->{'error_description'});
        }

        $this->setClientID($json_response->{'client_id'});

        // The OpenID Connect Dynamic registration protocol makes the client secret optional
        // and provides a registration access token and URI endpoint if it is not present
        if (isset($json_response->{'client_secret'})) {
            $this->setClientSecret($json_response->{'client_secret'});
        } else {
            throw new OpenIDConnectClientException("Error registering:
                                                    Please contact the OpenID Connect provider and obtain a Client ID and Secret directly from them");
        }

    }

    /**
     * @return mixed
     */
    public function getClientName()
    {
        return $this->clientName;
    }

    /**
     * @param $clientName
     */
    public function setClientName($clientName)
    {
        $this->clientName = $clientName;
    }

    /**
     * @return string
     */
    public function getClientID()
    {
        return $this->clientID;
    }

    /**
     * @return string
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * @return bool
     */
    public function canVerifySignatures()
    {
        return class_exists('\phpseclib\Crypt\RSA');
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @return string
     */
    public function getRefreshToken()
    {
        return $this->refreshToken;
    }

    /**
     * @return string
     */
    public function getIdToken()
    {
        return $this->idToken;
    }

    /**
     * @return array
     */
    public function getAccessTokenHeader()
    {
        return $this->decodeJWT($this->accessToken, 0);
    }

    /**
     * @return array
     */
    public function getAccessTokenPayload()
    {
        return $this->decodeJWT($this->accessToken, 1);
    }
}
