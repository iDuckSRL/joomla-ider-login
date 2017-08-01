IDER OpenID Generic Client for PHP
========================
A simple library that allows an application to authenticate a user through the IDer authentication platform.
This library hopes to encourage OpenID Connect use by making it simple enough for a developer with little knowledge of
the OpenID Connect protocol to setup authentication.

This work is based on OpenIDConnectClient for PHP5 by Michael Jett. Thanks.

# Requirements #
 1. PHP 5.4 or greater
 2. CURL extension
 3. JSON extension

## Install ##
 1. Install library using composer
```
composer require jlmsrl/ider-openid-client-php
```
 2. Include composer autoloader
```php
require '/vendor/autoload.php';
```

## Example 1: Basic Client ##

```php

// Set a log file
\IDERConnect\IDEROpenIDClient::$IDERLogFile = './ider-connect.log';

// Instanziate
$iderconnect = new \IDERConnect\IDEROpenIDClient($client_id, $client_secret, $extra_scopes);

// Set extra scopes or reset it
$iderconnect->setScope('my_extra_scope');


// Connect
$iderconnect->authenticate();

// Request user info
$userInfo = $iderconnect->requestUserInfo();

```

[See openid spec for available user attributes][1]

## Example 2: Network and Security ##
```php
// Configure a proxy
$oidc->setHttpProxy("http://my.proxy.com:80/");

```


[1]: http://openid.net/specs/openid-connect-basic-1_0-15.html#id_res
